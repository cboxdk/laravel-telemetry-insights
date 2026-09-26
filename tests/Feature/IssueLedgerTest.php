<?php

declare(strict_types=1);

use Cbox\TelemetryInsights\Issues\ChangeKind;
use Cbox\TelemetryInsights\Issues\IssueLedger;
use Cbox\TelemetryInsights\Issues\IssueStatus;
use Cbox\TelemetryInsights\Models\Issue;
use Cbox\TelemetryUi\Http\Api\RequestScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * The ledger is the only reason this package has a database. The telemetry
 * store can say an exception happened; only a ledger can say it never
 * happened before, or that it is back after someone called it fixed.
 */
function ledger(): IssueLedger
{
    return app(IssueLedger::class);
}

function window(): RequestScope
{
    return new RequestScope(period: '1h');
}

it('records a fingerprint it has never seen as new, and only once', function (): void {
    fakeBackends([exceptionStream('aaaa00000001', 'RedisException', 'refused', 'trace0001', 300, 3)]);

    $first = ledger()->record(window());

    expect($first)->toHaveCount(1)
        ->and($first[0]->kind)->toBe(ChangeKind::New)
        ->and($first[0]->count)->toBe(3)
        ->and($first[0]->issue->status)->toBe(IssueStatus::Open);

    // Same window again: still there, no longer news.
    $second = ledger()->record(window());

    expect($second[0]->kind)->toBe(ChangeKind::Recurring)
        ->and(Issue::query()->count())->toBe(1);
});

it('calls a resolved issue that fires again a regression, and reopens it', function (): void {
    fakeBackends([exceptionStream('aaaa00000001', 'RedisException', 'refused', 'trace0001', 3600)]);
    ledger()->record(window());

    $issue = Issue::query()->firstWhere('fingerprint', 'aaaa00000001');
    expect($issue)->toBeInstanceOf(Issue::class);
    $issue->forceFill([
        'status' => IssueStatus::Resolved,
        'resolved_at' => Carbon::now()->subMinutes(30),
        'resolved_in_release' => 'v2.4.1',
    ])->save();

    // It fires again, after the fix shipped.
    fakeBackends([exceptionStream('aaaa00000001', 'RedisException', 'refused', 'trace0001', 60)]);

    $changes = ledger()->record(window());

    expect($changes[0]->kind)->toBe(ChangeKind::Regression)
        ->and($changes[0]->issue->refresh()->status)->toBe(IssueStatus::Open)
        ->and($changes[0]->issue->resolved_at)->toBeNull();
});

it('does not call an occurrence from before the fix a regression', function (): void {
    fakeBackends([exceptionStream('aaaa00000001', 'RedisException', 'refused', 'trace0001', 3000)]);
    ledger()->record(window());

    Issue::query()->firstWhere('fingerprint', 'aaaa00000001')?->forceFill([
        'status' => IssueStatus::Resolved,
        // Resolved after everything in the window happened.
        'resolved_at' => Carbon::now()->addMinute(),
    ])->save();

    $changes = ledger()->record(window());

    expect($changes[0]->kind)->toBe(ChangeKind::Recurring)
        ->and($changes[0]->issue->refresh()->status)->toBe(IssueStatus::Resolved);
});

it('treats a lapsed snooze as open again without a sweep job', function (): void {
    $issue = new Issue;
    $issue->forceFill([
        'fingerprint' => 'aaaa00000001',
        'status' => IssueStatus::Snoozed,
        'snoozed_until' => Carbon::now()->subHour(),
    ])->save();

    expect($issue->effectiveStatus())->toBe(IssueStatus::Open);

    $issue->forceFill(['snoozed_until' => Carbon::now()->addHour()])->save();

    expect($issue->effectiveStatus())->toBe(IssueStatus::Snoozed)
        ->and($issue->effectiveStatus()->notifiable())->toBeFalse();
});

it('reports nothing rather than failing when the backend is unreachable', function (): void {
    Http::fake([
        'loki.test:3100/*' => Http::response('upstream is down', 502),
    ]);

    expect(ledger()->record(window()))->toBe([])
        ->and(Issue::query()->count())->toBe(0);
});
