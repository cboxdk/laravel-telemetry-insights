<?php

declare(strict_types=1);

use Cbox\TelemetryInsights\Contracts\CorrelatesIncidents;
use Cbox\TelemetryInsights\Correlate\CauseKind;
use Cbox\TelemetryInsights\Correlate\Confidence;
use Cbox\TelemetryUi\Discovery\Discoverer;
use Cbox\TelemetryUi\Http\Api\RequestScope;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The step past every other tool: not "your calls to the cache failed",
 * but "and the cache itself says it was out of memory". Discovery already
 * tied the address the app dialled to the exporter watching it; this is
 * the incident asking it what it saw.
 */

/** A burst of unrelated errors whose traces all hit the same dead cache. */
function redisBurst(): array
{
    return [
        exceptionStream('aaaa00000001', 'RedisException', 'refused', 'trace0001', 300, 4),
        exceptionStream('bbbb00000002', 'TypeError', 'null given', 'trace0002', 298, 3),
        exceptionStream('cccc00000003', 'RuntimeException', 'no session', 'trace0003', 296, 2),
    ];
}

/**
 * The whole stack: Loki with the errors, Tempo with a failing Redis call,
 * and a Prometheus that has redis_exporter for that node.
 */
function fakeStackWithExporter(float $current, float $baseline): void
{
    $spans = [clientSpan('cache.get', ['db.system.name' => 'redis', 'server.address' => 'cache-1', 'server.port' => '6379'], failed: true)];
    $range = static fn (float $v): array => ['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => [[
        'metric' => [], 'values' => [[time() - 120, (string) $v], [time(), (string) $v]],
    ]]]];

    $calls = 0;

    Http::fake([
        'loki.test:3100/loki/api/v1/query_range*' => Http::response(lokiStreams(redisBurst())),
        'tempo.test:3200/api/v2/search/tag/*' => Http::response(['tagValues' => [['type' => 'string', 'value' => 'cache-1:6379']]]),
        'tempo.test:3200/api/search*' => Http::response(['traces' => []]),
        'tempo.test:3200/api/traces/*' => Http::response(tempoTrace($spans)),
        'prometheus.test:9090/api/v1/label/__name__/values*' => Http::response(['status' => 'success', 'data' => ['redis_up', 'redis_memory_used_bytes']]),
        'prometheus.test:9090/api/v1/label/*/values*' => Http::response(['status' => 'success', 'data' => ['redis://cache-1:6379']]),
        // The window reads high; the baseline lookback reads normal.
        'prometheus.test:9090/api/v1/query_range*' => function () use (&$calls, $range, $current, $baseline) {
            $calls++;

            return Http::response($range($calls % 2 === 1 ? $current : $baseline));
        },
        'prometheus.test:9090/api/v1/query*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => [['metric' => [], 'value' => [time(), '1']]]]]),
    ]);
}

beforeEach(function (): void {
    Cache::flush();
    config()->set('telemetry-ui.context.signals', []);
});

it('lets the cache speak for itself when its own exporter agrees', function (): void {
    fakeStackWithExporter(current: 4.0, baseline: 1.0);
    app(Discoverer::class)->discover();

    $incident = app(CorrelatesIncidents::class)->correlate(new RequestScope(period: '1h'))[0];

    expect($incident->cause?->kind)->toBe(CauseKind::Dependency)
        ->and($incident->cause?->label)->toBe('redis cache-1:6379')
        ->and($incident->cause?->evidence)->toContain('Its own exporter agrees')
        // Corroboration from the dependency itself is the strongest
        // evidence available, so the verdict is not hedged.
        ->and($incident->cause?->confidence)->toBe(Confidence::High);
});

it('says only what the traces support when the exporter sees nothing unusual', function (): void {
    fakeStackWithExporter(current: 1.0, baseline: 1.0);
    app(Discoverer::class)->discover();

    $incident = app(CorrelatesIncidents::class)->correlate(new RequestScope(period: '1h'))[0];

    expect($incident->cause?->evidence)->toContain('traces inspected')
        ->not->toContain('Its own exporter');
});

it('still names the dependency when no exporter was ever discovered', function (): void {
    fakeBackends(redisBurst(), [
        clientSpan('cache.get', ['db.system.name' => 'redis', 'server.address' => 'cache-1', 'server.port' => '6379'], failed: true),
    ]);

    $incident = app(CorrelatesIncidents::class)->correlate(new RequestScope(period: '1h'))[0];

    expect($incident->cause?->kind)->toBe(CauseKind::Dependency)
        ->and($incident->cause?->evidence)->not->toContain('Its own exporter');
});
