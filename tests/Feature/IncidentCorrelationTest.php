<?php

declare(strict_types=1);

use Cbox\TelemetryInsights\Contracts\CorrelatesIncidents;
use Cbox\TelemetryInsights\Correlate\CauseKind;
use Cbox\TelemetryInsights\Correlate\Confidence;
use Cbox\TelemetryUi\Http\Api\RequestScope;

/**
 * The case the whole package exists for.
 *
 * A cache node dies. Nothing in the application says "the cache is down" —
 * instead four unrelated exceptions appear in four unrelated places, and a
 * per-error alert pages you four times about four different bugs. These
 * tests prove the burst folds back into one incident that names the cache.
 */
function correlator(): CorrelatesIncidents
{
    return app(CorrelatesIncidents::class);
}

function scope(): RequestScope
{
    return new RequestScope(period: '1h');
}

it('blames one dead cache node for four unrelated-looking exceptions', function (): void {
    fakeBackends(
        [
            exceptionStream('aaaa00000001', 'RedisException', 'Connection refused', 'trace0001', 300, 12),
            exceptionStream('bbbb00000002', 'TypeError', 'Argument must be array, null given', 'trace0002', 295, 8),
            exceptionStream('cccc00000003', 'RuntimeException', 'Session store not set', 'trace0003', 290, 5),
            exceptionStream('dddd00000004', 'ErrorException', 'Undefined array key "rate"', 'trace0004', 288, 3),
        ],
        // Every one of those requests went through the same Redis node, and
        // the call failed.
        [
            clientSpan('db.query', ['db.system.name' => 'mysql', 'server.address' => 'db-1', 'server.port' => '3306']),
            clientSpan('cache.get', ['db.system.name' => 'redis', 'server.address' => 'cache-1', 'server.port' => '6379'], failed: true),
        ],
    );

    $incidents = correlator()->correlate(scope());

    expect($incidents)->toHaveCount(1);

    $incident = $incidents[0];

    expect($incident->groups)->toHaveCount(4)
        ->and($incident->occurrences())->toBe(28)
        ->and($incident->cause)->not->toBeNull()
        ->and($incident->cause->kind)->toBe(CauseKind::Dependency)
        ->and($incident->cause->label)->toBe('redis cache-1:6379')
        ->and($incident->cause->confidence)->toBe(Confidence::High)
        ->and($incident->title())->toContain('redis cache-1:6379')
        ->and($incident->fingerprints())->toContain('aaaa00000001', 'dddd00000004');
});

it('does not blame the database every request happens to touch when it is healthy', function (): void {
    fakeBackends(
        [
            exceptionStream('aaaa00000001', 'RedisException', 'Connection refused', 'trace0001', 300),
            exceptionStream('bbbb00000002', 'TypeError', 'null given', 'trace0002', 295),
            exceptionStream('cccc00000003', 'RuntimeException', 'Session store not set', 'trace0003', 290),
        ],
        // MySQL is in every single trace — and perfectly fine. Sharing a
        // dependency is not evidence; sharing a *failing* one is.
        [clientSpan('db.query', ['db.system.name' => 'mysql', 'server.address' => 'db-1', 'server.port' => '3306'])],
    );

    $incidents = correlator()->correlate(scope());

    expect($incidents)->toHaveCount(1)
        ->and($incidents[0]->cause)->toBeNull()
        ->and($incidents[0]->title())->toBe('3 error groups started together');
});

it('leaves a single error group alone — one error is not an incident', function (): void {
    fakeBackends(
        [exceptionStream('aaaa00000001', 'RedisException', 'Connection refused', 'trace0001', 300, 50)],
        [clientSpan('cache.get', ['db.system.name' => 'redis', 'server.address' => 'cache-1', 'server.port' => '6379'], failed: true)],
    );

    expect(correlator()->correlate(scope()))->toBe([]);
});

it('keeps errors that started an hour apart in separate incidents', function (): void {
    fakeBackends([
        exceptionStream('aaaa00000001', 'RedisException', 'Connection refused', 'trace0001', 3000),
        exceptionStream('bbbb00000002', 'TypeError', 'null given', 'trace0002', 2995),
        exceptionStream('cccc00000003', 'RuntimeException', 'no session', 'trace0003', 2990),
        exceptionStream('dddd00000004', 'LogicException', 'later burst', 'trace0004', 300),
        exceptionStream('eeee00000005', 'DomainException', 'later burst', 'trace0005', 295),
        exceptionStream('ffff00000006', 'OutOfRangeException', 'later burst', 'trace0006', 290),
    ]);

    $incidents = correlator()->correlate(scope());

    expect($incidents)->toHaveCount(2)
        ->and($incidents[0]->groups)->toHaveCount(3)
        ->and($incidents[1]->groups)->toHaveCount(3)
        ->and($incidents[0]->onsetNano)->toBeLessThan($incidents[1]->onsetNano);
});

it('gives the same incident the same signature on a second pass, even as it grows', function (): void {
    $spans = [clientSpan('cache.get', ['db.system.name' => 'redis', 'server.address' => 'cache-1', 'server.port' => '6379'], failed: true)];

    $burst = [
        exceptionStream('aaaa00000001', 'RedisException', 'refused', 'trace0001', 300),
        exceptionStream('bbbb00000002', 'TypeError', 'null', 'trace0002', 298),
        exceptionStream('cccc00000003', 'RuntimeException', 'no session', 'trace0003', 296),
    ];

    fakeBackends($burst, $spans);

    $first = correlator()->correlate(scope())[0];

    // The same burst, one more group caught up in it.
    fakeBackends([...$burst, exceptionStream('dddd00000004', 'ErrorException', 'undefined key', 'trace0004', 294)], $spans);

    $second = correlator()->correlate(scope())[0];

    expect($second->groups)->toHaveCount(4)
        ->and($second->signature)->toBe($first->signature);
});

it('correlates a scoped window without blowing up on the scope labels', function (): void {
    fakeBackends(
        [
            exceptionStream('aaaa00000001', 'RedisException', 'refused', 'trace0001', 300),
            exceptionStream('bbbb00000002', 'TypeError', 'null', 'trace0002', 298),
            exceptionStream('cccc00000003', 'RuntimeException', 'no session', 'trace0003', 296),
        ],
        [],
    );

    // Everything else in this file scans every service. An install that
    // pins one reaches the host-pressure path, which builds Prometheus
    // labels — and did so with dimension names ScopeLabels rejects.
    $incidents = correlator()->correlate(new RequestScope(period: '1h', service: 'checkout', environment: 'production'));

    expect($incidents)->toHaveCount(1);
});
