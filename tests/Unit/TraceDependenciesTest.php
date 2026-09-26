<?php

declare(strict_types=1);

use Cbox\TelemetryInsights\Correlate\DependencyKind;
use Cbox\TelemetryInsights\Correlate\TraceDependencies;
use Cbox\TelemetryUi\Queries\Results\Span;
use Cbox\TelemetryUi\Queries\Results\SpanKind;
use Cbox\TelemetryUi\Queries\Results\Trace;

/**
 * Identifying a dependency is the part of correlation that has to be right:
 * too loose and every trace shares everything, too tight and two calls to
 * the same node look like two dependencies.
 */
function span(SpanKind $kind, array $attributes, bool $error = false): Span
{
    return new Span('s1', null, 'call', 'checkout', $kind, 0, 1_000_000, $attributes, $error);
}

it('reads a cache, a database, a queue and an HTTP peer out of one trace', function (): void {
    $trace = new Trace('t1', [
        span(SpanKind::Server, ['http.route' => '/checkout']),
        span(SpanKind::Client, ['db.system.name' => 'redis', 'server.address' => 'cache-1', 'server.port' => '6379']),
        span(SpanKind::Client, ['db.system.name' => 'mysql', 'server.address' => 'db-1', 'server.port' => '3306']),
        span(SpanKind::Producer, ['messaging.system' => 'sqs', 'messaging.destination.name' => 'invoices']),
        span(SpanKind::Client, ['http.request.method' => 'POST', 'server.address' => 'api.stripe.com', 'server.port' => '443']),
    ]);

    $found = (new TraceDependencies)->of($trace);
    $kinds = array_map(static fn (array $d): DependencyKind => $d['signature']->kind, $found);

    expect($found)->toHaveCount(4)
        ->and(array_values($kinds))->toEqualCanonicalizing([
            DependencyKind::Cache,
            DependencyKind::Database,
            DependencyKind::Queue,
            DependencyKind::Http,
        ]);
});

it('calls redis a cache, because "the cache is down" is the sentence people want', function (): void {
    $trace = new Trace('t1', [span(SpanKind::Client, ['db.system.name' => 'redis', 'server.address' => 'cache-1'])]);
    $found = array_values((new TraceDependencies)->of($trace));

    expect($found[0]['signature']->kind)->toBe(DependencyKind::Cache)
        ->and($found[0]['signature']->label())->toBe('redis cache-1');
});

it('treats two calls to the same node as one dependency, whatever the statement', function (): void {
    $trace = new Trace('t1', [
        span(SpanKind::Client, ['db.system.name' => 'mysql', 'server.address' => 'db-1', 'db.query.text' => 'select 1']),
        span(SpanKind::Client, ['db.system.name' => 'mysql', 'server.address' => 'db-1', 'db.query.text' => 'select 2']),
    ]);

    expect((new TraceDependencies)->of($trace))->toHaveCount(1);
});

it('marks a dependency failed when any one call to it failed', function (): void {
    $trace = new Trace('t1', [
        span(SpanKind::Client, ['db.system.name' => 'redis', 'server.address' => 'cache-1']),
        span(SpanKind::Client, ['db.system.name' => 'redis', 'server.address' => 'cache-1'], error: true),
    ]);

    $found = array_values((new TraceDependencies)->of($trace));

    expect($found)->toHaveCount(1)
        ->and($found[0]['failed'])->toBeTrue();
});

it('ignores the request itself — a server span is not a dependency', function (): void {
    $trace = new Trace('t1', [
        span(SpanKind::Server, ['http.route' => '/checkout', 'server.address' => 'web-1']),
        span(SpanKind::Internal, ['db.system.name' => 'mysql', 'server.address' => 'db-1']),
    ]);

    expect((new TraceDependencies)->of($trace))->toBe([]);
});

it('falls back to the deprecated network.peer attributes older spans carry', function (): void {
    $trace = new Trace('t1', [
        span(SpanKind::Client, ['db.system.name' => 'pgsql', 'network.peer.address' => '10.0.0.4', 'network.peer.port' => '5432']),
    ]);

    $found = array_values((new TraceDependencies)->of($trace));

    expect($found[0]['signature']->label())->toBe('pgsql 10.0.0.4:5432');
});

it('skips an outbound HTTP call with no address — nothing to recognise it by', function (): void {
    $trace = new Trace('t1', [span(SpanKind::Client, ['http.request.method' => 'GET'])]);

    expect((new TraceDependencies)->of($trace))->toBe([]);
});

it('names a queue by its destination, not by the broker host', function (): void {
    $trace = new Trace('t1', [
        span(SpanKind::Producer, ['messaging.system' => 'redis', 'messaging.destination.name' => 'emails', 'server.address' => 'cache-1']),
    ]);

    $found = array_values((new TraceDependencies)->of($trace));

    expect($found[0]['signature']->kind)->toBe(DependencyKind::Queue)
        ->and($found[0]['signature']->label())->toBe('redis emails');
});
