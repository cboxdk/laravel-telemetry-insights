<?php

declare(strict_types=1);

use Cbox\TelemetryInsights\Alerts\AlertType;
use Cbox\TelemetryInsights\Digest\DigestBuilder;
use Cbox\TelemetryInsights\Issues\ChangeKind;
use Cbox\TelemetryInsights\Issues\IssueLedger;
use Cbox\TelemetryInsights\Issues\IssueStatus;
use Cbox\TelemetryInsights\Models\AlertRule;
use Cbox\TelemetryInsights\Models\Issue;
use Cbox\TelemetryInsights\Scan\Scanner;
use Cbox\TelemetryUi\Http\Api\RequestScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Three bugs a polish read found that the suite had walked past. Each one
 * is here so it cannot come back.
 */
function oneError(int $secondsAgo = 300, int $times = 1): void
{
    fakeBackends([exceptionStream('aaaa00000001', 'RedisException', 'refused', 'trace0001', $secondsAgo, $times)]);
    Http::fake([
        'loki.test:3100/loki/api/v1/query_range*' => fn () => Http::response(lokiStreams([
            exceptionStream('aaaa00000001', 'RedisException', 'refused', 'trace0001', $secondsAgo, $times),
        ])),
        'tempo.test:3200/api/search*' => Http::response(['traces' => []]),
        'tempo.test:3200/api/traces/*' => Http::response(tempoTrace([])),
        'prometheus.test:9090/api/v1/query*' => Http::response(promVector([])),
    ]);
}

it('does not remember anything just because you asked for a digest', function (): void {
    oneError();

    // Summarising the week must not mark the window as seen. If it did,
    // running the digest before the scan would leave the scan with nothing
    // to announce — a report would quietly disarm the alerting.
    app(DigestBuilder::class)->build(new RequestScope(period: '24h'));

    expect(Issue::query()->count())->toBe(0);

    $changes = app(IssueLedger::class)->record(new RequestScope(period: '24h'));

    expect($changes[0]->kind)->toBe(ChangeKind::New)
        ->and(Issue::query()->count())->toBe(1);
});

it('still reports what is new in the digest, it just does not write it down', function (): void {
    oneError();

    $digest = app(DigestBuilder::class)->build(new RequestScope(period: '24h'));

    expect($digest->findings)->not->toBeEmpty()
        ->and($digest->findings[0]->title)->toContain('is new')
        ->and(Issue::query()->count())->toBe(0);
});

it('fires the regression rule on a regression, not the new-issue one', function (): void {
    $notifier = $this->fakeNotifications();
    oneError(secondsAgo: 3600);
    app(IssueLedger::class)->record(new RequestScope(period: '24h'));

    Carbon::setTestNow(Carbon::now()->subMinutes(30));
    Issue::query()->firstWhere('fingerprint', 'aaaa00000001')?->forceFill([
        'status' => IssueStatus::Resolved,
        'resolved_at' => Carbon::now(),
    ])->save();
    Carbon::setTestNow();

    $newIssueRule = AlertRule::create(['name' => 'Anything new', 'type' => AlertType::NewIssue, 'enabled' => true]);
    $regressionRule = AlertRule::create(['name' => 'Something came back', 'type' => AlertType::Regression, 'enabled' => true]);

    oneError(secondsAgo: 30);
    app(Scanner::class)->scan(new RequestScope(period: '24h'));

    expect($regressionRule->refresh()->last_fired_at)->not->toBeNull()
        // The exception is not new — somebody had already seen and fixed it.
        ->and($newIssueRule->refresh()->last_fired_at)->toBeNull();

    $notifier->assertSent('Regression: RedisException');
});

it('names the rest rather than firing once per fingerprint', function (): void {
    fakeBackends([
        exceptionStream('aaaa00000001', 'RedisException', 'a', 'trace0001', 300),
        exceptionStream('bbbb00000002', 'TypeError', 'b', 'trace0002', 300),
        exceptionStream('cccc00000003', 'LogicException', 'c', 'trace0003', 300),
    ]);

    $rule = AlertRule::create(['name' => 'Anything new', 'type' => AlertType::NewIssue, 'enabled' => true]);

    app(Scanner::class)->scan(new RequestScope(period: '24h'));

    $event = $rule->events()->first();

    expect($rule->events()->count())->toBe(1)
        ->and($event?->summary)->toContain('and 2 others')
        ->and($event?->context['count'])->toBe(3);
});

it('reads the ledger in one query, not one per error group', function (): void {
    fakeBackends(array_map(
        static fn (int $i): array => exceptionStream(
            str_pad((string) $i, 12, '0', STR_PAD_LEFT), 'Exception'.$i, 'm', 'trace'.$i, 300,
        ),
        range(1, 25),
    ));

    DB::enableQueryLog();
    app(IssueLedger::class)->record(new RequestScope(period: '24h'));
    $selects = array_filter(DB::getQueryLog(), static fn (array $q): bool => str_starts_with(strtolower(trim($q['query'])), 'select'));
    DB::disableQueryLog();

    // One lookup for the whole window. The inserts are still per row; it is
    // the read that used to be N+1, on a job that runs every few minutes.
    expect($selects)->toHaveCount(1)
        ->and(Issue::query()->count())->toBe(25);
});
