<?php

declare(strict_types=1);

use Cbox\TelemetryInsights\Alerts\AlertType;
use Cbox\TelemetryInsights\Issues\IssueLedger;
use Cbox\TelemetryInsights\Issues\SpikeDetector;
use Cbox\TelemetryInsights\Models\AlertRule;
use Cbox\TelemetryInsights\Scan\Scanner;
use Cbox\TelemetryUi\Http\Api\RequestScope;
use Illuminate\Support\Facades\Http;

/**
 * A spike is a known issue firing materially harder than it was in the
 * window before. The guards matter more than the arithmetic: a brand new
 * issue is not a spike, tiny numbers are not a spike, and an unreadable
 * baseline is not an infinite spike.
 */

/** How many times the window was read from Loki. */
function lokiReads(): int
{
    $reads = 0;

    Http::assertSent(function ($request) use (&$reads): bool {
        if (str_contains((string) $request->url(), '/loki/api/v1/query_range')) {
            $reads++;
        }

        return true;
    });

    return $reads;
}

/**
 * The two windows, as mutable state — Http::fake() merges rather than
 * replaces, so a test that changes what the window holds has to move a
 * holder rather than fake twice.
 *
 * @param  list<array<string, mixed>>|null  $now
 * @param  list<array<string, mixed>>|null  $before
 * @return array{now: list<array<string, mixed>>, before: list<array<string, mixed>>}
 */
function windowState(?array $now = null, ?array $before = null): array
{
    static $state = ['now' => [], 'before' => []];

    if ($now !== null) {
        $state = ['now' => $now, 'before' => $before ?? []];
    }

    return $state;
}

/**
 * Loki answers the current window with `now` and the previous one with
 * `before`, keyed on the nanosecond `start` the reader sends.
 *
 * @param  list<array<string, mixed>>  $now
 * @param  list<array<string, mixed>>  $before
 */
function fakeTwoWindows(array $now, array $before): void
{
    windowState($now, $before);

    // The current window starts at now-900s and the baseline at now-1800s,
    // so anything starting before this is the baseline read.
    $boundary = (time() - 1000) * 1_000_000_000;

    Http::fake([
        'loki.test:3100/loki/api/v1/query_range*' => function ($request) use ($boundary) {
            parse_str((string) parse_url((string) $request->url(), PHP_URL_QUERY), $query);
            $start = (int) ($query['start'] ?? 0);
            $state = windowState();

            return Http::response(lokiStreams($start < $boundary ? $state['before'] : $state['now']));
        },
        'tempo.test:3200/api/search*' => Http::response(['traces' => []]),
        'tempo.test:3200/api/traces/*' => Http::response(tempoTrace([])),
    ]);
}

it('finds an issue firing several times harder than the window before', function (): void {
    fakeTwoWindows(
        now: [exceptionStream('aaaa00000001', 'PaymentDeclined', 'card', 'trace0001', 300, 40)],
        before: [exceptionStream('aaaa00000001', 'PaymentDeclined', 'card', 'trace0001', 2000, 4)],
    );

    $spikes = app(SpikeDetector::class)->against(
        new RequestScope(period: '15m'),
        ['aaaa00000001' => 40],
        3.0,
        ['aaaa00000001' => 'PaymentDeclined'],
    );

    expect($spikes)->toHaveCount(1)
        ->and($spikes[0]->baseline)->toBe(4)
        ->and($spikes[0]->multiplier)->toBe(10.0)
        ->and($spikes[0]->summary())->toContain('10× its usual rate');
});

it('does not call three occurrences a spike just because there was one before', function (): void {
    fakeTwoWindows(
        now: [exceptionStream('aaaa00000001', 'PaymentDeclined', 'card', 'trace0001', 300, 3)],
        before: [exceptionStream('aaaa00000001', 'PaymentDeclined', 'card', 'trace0001', 2000, 1)],
    );

    expect(app(SpikeDetector::class)->against(new RequestScope(period: '15m'), ['aaaa00000001' => 3], 3.0))
        ->toBe([]);
});

it('does not call a brand new issue a spike — it is already announced as new', function (): void {
    fakeTwoWindows(
        now: [exceptionStream('aaaa00000001', 'PaymentDeclined', 'card', 'trace0001', 300, 40)],
        before: [],
    );

    expect(app(SpikeDetector::class)->against(new RequestScope(period: '15m'), ['aaaa00000001' => 40], 3.0))
        ->toBe([]);
});

it('treats an unreadable baseline as no spike, never as an infinite one', function (): void {
    Http::fake(['loki.test:3100/*' => Http::response('down', 502)]);

    expect(app(SpikeDetector::class)->against(new RequestScope(period: '15m'), ['aaaa00000001' => 900], 3.0))
        ->toBe([]);
});

it('costs nothing when no rule is watching for a spike', function (): void {
    fakeBackends([exceptionStream('aaaa00000001', 'PaymentDeclined', 'card', 'trace0001', 300, 40)]);
    app(IssueLedger::class)->record(new RequestScope(period: '15m'));

    Http::fake([
        'loki.test:3100/loki/api/v1/query_range*' => Http::response(lokiStreams([
            exceptionStream('aaaa00000001', 'PaymentDeclined', 'card', 'trace0001', 300, 40),
        ])),
        'tempo.test:3200/api/search*' => Http::response(['traces' => []]),
    ]);

    $result = app(Scanner::class)->scan(new RequestScope(period: '15m'));

    expect($result->spikes)->toBe(0)
        // The pass reads the window for the ledger and again for
        // correlation — and does NOT read the window before it.
        ->and(lokiReads())->toBe(2);
});

it('fires a spike rule through the scan', function (): void {
    $notifier = $this->fakeNotifications();

    // First pass records the issue so it is no longer new.
    fakeTwoWindows(
        now: [exceptionStream('aaaa00000001', 'PaymentDeclined', 'card', 'trace0001', 300, 4)],
        before: [exceptionStream('aaaa00000001', 'PaymentDeclined', 'card', 'trace0001', 2000, 4)],
    );
    app(IssueLedger::class)->record(new RequestScope(period: '15m'));

    AlertRule::create([
        'name' => 'Something is spiking',
        'type' => AlertType::IssueSpike,
        'threshold' => 3.0,
        'window_minutes' => 15,
        'cooldown_minutes' => 30,
        'enabled' => true,
    ]);

    fakeTwoWindows(
        now: [exceptionStream('aaaa00000001', 'PaymentDeclined', 'card', 'trace0001', 300, 40)],
        before: [exceptionStream('aaaa00000001', 'PaymentDeclined', 'card', 'trace0001', 2000, 4)],
    );

    $result = app(Scanner::class)->scan(new RequestScope(period: '15m'));

    expect($result->spikes)->toBe(1);
    $notifier->assertSent('Something is spiking');
});
