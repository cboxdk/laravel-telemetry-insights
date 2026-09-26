<?php

declare(strict_types=1);

use Cbox\TelemetryInsights\Models\Incident;
use Cbox\TelemetryInsights\Models\Issue;
use Cbox\TelemetryInsights\Scan\Scanner;
use Cbox\TelemetryUi\Http\Api\RequestScope;

/**
 * One pass, end to end: read the window, fold it into the ledger,
 * correlate, persist, and announce only what is news.
 */
function redisOutage(): void
{
    fakeBackends(
        [
            exceptionStream('aaaa00000001', 'RedisException', 'Connection refused', 'trace0001', 300, 4),
            exceptionStream('bbbb00000002', 'TypeError', 'null given', 'trace0002', 298, 3),
            exceptionStream('cccc00000003', 'RuntimeException', 'Session store not set', 'trace0003', 296, 2),
        ],
        [clientSpan('cache.get', ['db.system.name' => 'redis', 'server.address' => 'cache-1', 'server.port' => '6379'], failed: true)],
    );
}

it('records the issues and the incident, and announces both', function (): void {
    $notifier = $this->fakeNotifications();
    redisOutage();

    $result = app(Scanner::class)->scan(new RequestScope(period: '1h'));

    expect($result->issuesSeen)->toBe(3)
        ->and($result->newIssues)->toBe(3)
        ->and($result->incidents)->toBe(1)
        ->and(Issue::query()->count())->toBe(3)
        ->and(Incident::query()->count())->toBe(1);

    $incident = Incident::query()->first();
    expect($incident)->toBeInstanceOf(Incident::class)
        ->and($incident->cause_label)->toBe('redis cache-1:6379')
        ->and($incident->group_count)->toBe(3)
        ->and($incident->fingerprints)->toContain('aaaa00000001');

    // Three new issues plus one incident.
    $notifier->assertSentCount(4);
    $notifier->assertSent('Incident: redis cache-1:6379');
});

it('goes quiet on the second pass — the same errors are no longer news', function (): void {
    redisOutage();
    app(Scanner::class)->scan(new RequestScope(period: '1h'));

    $notifier = $this->fakeNotifications();
    $result = app(Scanner::class)->scan(new RequestScope(period: '1h'));

    expect($result->issuesSeen)->toBe(3)
        ->and($result->newIssues)->toBe(0)
        ->and($result->incidents)->toBe(0)
        ->and(Incident::query()->count())->toBe(1);

    $notifier->assertNothingSent();
});

it('never announces an issue somebody ignored', function (): void {
    redisOutage();
    app(Scanner::class)->scan(new RequestScope(period: '1h'));

    Issue::query()->update(['status' => 'ignored', 'resolved_at' => null]);

    // The same fingerprints fire again, harder.
    fakeBackends(
        [exceptionStream('aaaa00000001', 'RedisException', 'Connection refused', 'trace0001', 60, 99)],
        [clientSpan('cache.get', ['db.system.name' => 'redis', 'server.address' => 'cache-1'], failed: true)],
    );

    $notifier = $this->fakeNotifications();
    app(Scanner::class)->scan(new RequestScope(period: '1h'));

    $notifier->assertNothingSent();
});

it('runs the scan command against a window', function (): void {
    redisOutage();

    $this->artisan('telemetry-insights:scan', ['--window' => '1h'])
        ->assertSuccessful();

    expect(Issue::query()->count())->toBe(3);
});
