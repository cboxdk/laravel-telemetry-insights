<?php

declare(strict_types=1);

use Cbox\TelemetryInsights\Digest\DigestBuilder;
use Cbox\TelemetryInsights\Digest\FindingKind;
use Cbox\TelemetryInsights\Tests\Fixtures\AggregatingTraces;
use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Http\Api\RequestScope;
use Cbox\TelemetryUi\Queries\Results\SpanBucket;
use Illuminate\Support\Facades\Http;

/**
 * The database half of a digest. It needs a store that aggregates spans
 * server-side, so these run against the contract through a real driver
 * registration rather than a stubbed builder.
 *
 * @param  list<SpanBucket>  $buckets
 */
function withAggregatingStore(array $buckets): void
{
    app(ConnectionManager::class)->extend(
        'fixture-traces',
        static fn (): AggregatingTraces => new AggregatingTraces($buckets),
    );

    config()->set('telemetry-ui.connections.traces', ['driver' => 'fixture-traces']);
}

beforeEach(function (): void {
    fakeBackends([]);
    Http::fake(['prometheus.test:9090/api/v1/query*' => Http::response(promVector([]))]);
});

it('reports a statement that is slow on average', function (): void {
    withAggregatingStore([
        new SpanBucket('select * from orders where id = ?', 40, 240.5, 300.0, 400.0, 9620.0, ['db.system.name' => 'mysql']),
    ]);

    $digest = app(DigestBuilder::class)->build(new RequestScope(period: '24h'));
    $findings = $digest->ofKind(FindingKind::SlowQuery);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->title)->toContain('240.5ms')
        ->and($findings[0]->facts['Statement'])->toBe('select * from orders where id = ?')
        ->and($findings[0]->facts['System'])->toBe('mysql')
        ->and($findings[0]->facts['Calls'])->toBe('40');
});

it('reports a fast statement that runs hundreds of times — the shape of a loop', function (): void {
    withAggregatingStore([
        new SpanBucket('select * from addresses where user_id = ?', 1184, 1.2, 2.0, 4.0, 1420.8, ['db.system.name' => 'mysql']),
    ]);

    $digest = app(DigestBuilder::class)->build(new RequestScope(period: '24h'));
    $findings = $digest->ofKind(FindingKind::RepeatedQuery);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->title)->toBe('Query called 1184 times')
        ->and($findings[0]->detail)->toContain('a query inside a loop')
        // Fast, so it must not also be reported as slow.
        ->and($digest->ofKind(FindingKind::SlowQuery))->toBe([]);
});

it('says nothing about a statement that is neither slow nor frequent', function (): void {
    withAggregatingStore([
        new SpanBucket('select 1', 4, 0.8, 1.0, 2.0, 3.2, []),
    ]);

    $digest = app(DigestBuilder::class)->build(new RequestScope(period: '24h'));

    expect($digest->isEmpty())->toBeTrue();
});

it('ranks the slow query above the repeated one, and both below an incident', function (): void {
    withAggregatingStore([
        new SpanBucket('slow one', 40, 240.5, 300.0, 400.0, 9620.0, []),
        new SpanBucket('busy one', 1184, 1.2, 2.0, 4.0, 1420.8, []),
    ]);

    $digest = app(DigestBuilder::class)->build(new RequestScope(period: '24h'));
    $kinds = array_map(static fn ($f): string => $f->kind->value, $digest->findings);

    expect($kinds)->toBe(['slow_query', 'repeated_query']);
});

it('contributes nothing when the store cannot aggregate spans', function (): void {
    // The default Tempo driver has no server-side aggregation; the digest
    // must simply skip this source rather than fail.
    $digest = app(DigestBuilder::class)->build(new RequestScope(period: '24h'));

    expect($digest->ofKind(FindingKind::SlowQuery))->toBe([])
        ->and($digest->ofKind(FindingKind::RepeatedQuery))->toBe([]);
});
