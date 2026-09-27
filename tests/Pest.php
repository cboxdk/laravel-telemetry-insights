<?php

declare(strict_types=1);

use Cbox\TelemetryInsights\Tests\BrowserTestCase;
use Cbox\TelemetryInsights\Tests\TestCase;
use Illuminate\Support\Facades\Http;

// Named rather than blanket: Pest refuses overlapping bindings, and the
// browser suite needs its own case — the fixture backends and the seeded
// issues without which those screens render their empty state.
uses(TestCase::class)->in('Feature', 'Unit');
uses(BrowserTestCase::class)->in('Browser');

/**
 * An exception record as cboxdk/laravel-telemetry writes it to Loki: the
 * fingerprint, class and message are stream labels, the values are the
 * occurrences.
 *
 * @return array<string, mixed>
 */
function exceptionStream(string $group, string $type, string $message, string $traceId, int $secondsAgo, int $occurrences = 1): array
{
    $values = [];

    for ($i = 0; $i < $occurrences; $i++) {
        $values[] = [(string) ((time() - $secondsAgo + $i) * 1_000_000_000), 'exception'];
    }

    return [
        'stream' => [
            'service_name' => 'checkout',
            'exception_group' => $group,
            'exception_type' => $type,
            'exception_message' => $message,
            'trace_id' => $traceId,
        ],
        'values' => $values,
    ];
}

/**
 * A Loki streams response.
 *
 * @param  list<array<string, mixed>>  $streams
 * @return array<string, mixed>
 */
function lokiStreams(array $streams): array
{
    return ['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => $streams]];
}

/**
 * One outbound span, in the OTLP shape Tempo returns.
 *
 * @param  array<string, string>  $attributes
 * @return array<string, mixed>
 */
function clientSpan(string $name, array $attributes, bool $failed = false): array
{
    $otlp = [];

    foreach ($attributes as $key => $value) {
        $otlp[] = ['key' => $key, 'value' => ['stringValue' => $value]];
    }

    $span = [
        'spanId' => substr(md5($name.serialize($attributes)), 0, 16),
        'name' => $name,
        'kind' => 'SPAN_KIND_CLIENT',
        'startTimeUnixNano' => '1735689600000000000',
        'endTimeUnixNano' => '1735689600500000000',
        'attributes' => $otlp,
    ];

    if ($failed) {
        $span['status'] = ['code' => 'STATUS_CODE_ERROR', 'message' => 'connection refused'];
    }

    return $span;
}

/**
 * A Tempo trace made of a server span plus the given outbound spans.
 *
 * @param  list<array<string, mixed>>  $spans
 * @return array<string, mixed>
 */
function tempoTrace(array $spans): array
{
    return [
        'batches' => [[
            'resource' => ['attributes' => [['key' => 'service.name', 'value' => ['stringValue' => 'checkout']]]],
            'scopeSpans' => [['spans' => [
                [
                    'spanId' => 'aaaaaaaaaaaaaaaa',
                    'name' => 'GET /checkout',
                    'kind' => 'SPAN_KIND_SERVER',
                    'startTimeUnixNano' => '1735689600000000000',
                    'endTimeUnixNano' => '1735689601000000000',
                ],
                ...$spans,
            ]]],
        ]],
    ];
}

/**
 * The backend a test is reading, as mutable state.
 *
 * Laravel merges successive Http::fake() calls and the first matching stub
 * wins, so a test that wants the window to CHANGE cannot just fake twice.
 * One stub reads this holder instead, and fakeBackends() moves it.
 *
 * @param  list<array<string, mixed>>|null  $streams
 * @param  list<array<string, mixed>>|null  $spans
 * @return array{streams: list<array<string, mixed>>, spans: list<array<string, mixed>>}
 */
function backendState(?array $streams = null, ?array $spans = null): array
{
    static $state = ['streams' => [], 'spans' => []];

    if ($streams !== null) {
        $state = ['streams' => $streams, 'spans' => $spans ?? []];
    }

    return $state;
}

/**
 * A whole backend for one test: the exception records in Loki, and a Tempo
 * that answers every trace lookup with the same trace. Call it again to
 * change what the next read sees.
 *
 * The empty search stub matters: the error reader falls back to Tempo for
 * browser exceptions, and an unstubbed call there fails the read — which
 * the callers then (correctly) treat as "no data".
 *
 * @param  list<array<string, mixed>>  $streams
 * @param  list<array<string, mixed>>  $spans
 */
function fakeBackends(array $streams, array $spans = []): void
{
    backendState($streams, $spans);

    Http::fake([
        'loki.test:3100/loki/api/v1/query_range*' => fn () => Http::response(lokiStreams(backendState()['streams'])),
        'tempo.test:3200/api/search*' => Http::response(['traces' => []]),
        'tempo.test:3200/api/traces/*' => fn () => Http::response(tempoTrace(backendState()['spans'])),
    ]);
}

/**
 * A Prometheus instant-vector response.
 *
 * @param  list<array{labels: array<string, string>, value: float}>  $series
 * @return array<string, mixed>
 */
function promVector(array $series): array
{
    $result = [];

    foreach ($series as $entry) {
        $result[] = ['metric' => $entry['labels'], 'value' => [time(), (string) $entry['value']]];
    }

    return ['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => $result]];
}
