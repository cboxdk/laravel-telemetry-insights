<?php

declare(strict_types=1);

use Cbox\TelemetryInsights\Digest\DigestBuilder;
use Cbox\TelemetryInsights\Digest\FindingKind;
use Cbox\TelemetryUi\Http\Api\RequestScope;
use Illuminate\Support\Facades\Http;

/**
 * The digest is the hand-off: findings a developer can act on, and a brief
 * an assistant can take from there without being told anything else.
 */
it('ranks an incident above a slow route and writes a brief that stands alone', function (): void {
    fakeBackends(
        [
            exceptionStream('aaaa00000001', 'RedisException', 'Connection refused', 'trace0001', 300, 4),
            exceptionStream('bbbb00000002', 'TypeError', 'null given', 'trace0002', 298, 3),
            exceptionStream('cccc00000003', 'RuntimeException', 'no session', 'trace0003', 296, 2),
        ],
        [clientSpan('cache.get', ['db.system.name' => 'redis', 'server.address' => 'cache-1', 'server.port' => '6379'], failed: true)],
    );

    // The p95-by-route lookup the digest makes for slow routes.
    Http::fake([
        'prometheus.test:9090/api/v1/query*' => Http::response(promVector([
            ['labels' => ['http_route' => '/checkout'], 'value' => 2400.0],
            ['labels' => ['http_route' => '/health'], 'value' => 12.0],
        ])),
    ]);

    $digest = app(DigestBuilder::class)->build(new RequestScope(period: '24h'));

    expect($digest->isEmpty())->toBeFalse()
        ->and($digest->findings[0]->kind)->toBe(FindingKind::Incident)
        ->and($digest->ofKind(FindingKind::SlowRoute))->toHaveCount(1)
        ->and($digest->ofKind(FindingKind::NewIssue))->toHaveCount(3)
        // /health is inside the budget, so it is not a finding.
        ->and($digest->headline())->toContain('redis cache-1:6379');

    $brief = $digest->toMarkdown();

    expect($brief)->toContain('# Telemetry findings')
        ->toContain('redis cache-1:6379')
        ->toContain('/checkout')
        ->toContain('2400ms')
        // The brief must ask for what it wants, or it is just a paste.
        ->toContain('look for the cause in the code')
        ->not->toContain('/health');
});

it('says so plainly when there is nothing to report', function (): void {
    fakeBackends([]);
    Http::fake(['prometheus.test:9090/api/v1/query*' => Http::response(promVector([]))]);

    $digest = app(DigestBuilder::class)->build(new RequestScope(period: '24h'));

    expect($digest->isEmpty())->toBeTrue()
        ->and($digest->headline())->toBe('Nothing worth your attention.')
        ->and($digest->toMarkdown())->toContain('No findings in this window.');
});

it('prints the brief and nothing else with --markdown, so it pipes', function (): void {
    fakeBackends([]);
    Http::fake(['prometheus.test:9090/api/v1/query*' => Http::response(promVector([]))]);

    $this->artisan('telemetry-insights:digest', ['--window' => '24h', '--markdown' => true])
        ->expectsOutputToContain('# Telemetry findings')
        ->assertSuccessful();
});
