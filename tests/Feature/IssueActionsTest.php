<?php

declare(strict_types=1);

use Cbox\TelemetryInsights\Issues\ChangeKind;
use Cbox\TelemetryInsights\Issues\IssueActions;
use Cbox\TelemetryInsights\Issues\IssueLedger;
use Cbox\TelemetryInsights\Issues\IssueStatus;
use Cbox\TelemetryInsights\Models\Issue;
use Cbox\TelemetryUi\Http\Api\RequestScope;
use Illuminate\Support\Carbon;

/**
 * Without a way to resolve something, regression detection can never fire:
 * nothing is ever fixed, so nothing ever comes back. These cover the loop
 * end to end — resolve it, watch it return, see it reopen itself.
 */
function recordOne(int $secondsAgo = 300): Issue
{
    fakeBackends([exceptionStream('aaaa00000001', 'RedisException', 'refused', 'trace0001', $secondsAgo)]);
    app(IssueLedger::class)->record(new RequestScope(period: '1h'));

    $issue = Issue::query()->firstWhere('fingerprint', 'aaaa00000001');
    expect($issue)->toBeInstanceOf(Issue::class);

    return $issue;
}

it('closes the loop: resolve it, it fires again, it reopens as a regression', function (): void {
    $issue = recordOne(secondsAgo: 3600);

    // Resolved half an hour ago; the return has to be newer than that, or
    // it is just an old occurrence being re-read.
    Carbon::setTestNow(Carbon::now()->subMinutes(30));
    app(IssueActions::class)->resolve($issue, by: 'sylvester', release: 'v2.4.1');
    Carbon::setTestNow();

    expect($issue->refresh()->status)->toBe(IssueStatus::Resolved)
        ->and($issue->resolved_by)->toBe('sylvester')
        ->and($issue->resolved_in_release)->toBe('v2.4.1');

    // It comes back after the fix shipped.
    fakeBackends([exceptionStream('aaaa00000001', 'RedisException', 'refused', 'trace0001', 30)]);
    $changes = app(IssueLedger::class)->record(new RequestScope(period: '1h'));

    expect($changes[0]->kind)->toBe(ChangeKind::Regression)
        ->and($issue->refresh()->status)->toBe(IssueStatus::Open);
});

it('silences an ignored issue for good', function (): void {
    $issue = recordOne();

    app(IssueActions::class)->ignore($issue);

    expect($issue->refresh()->effectiveStatus())->toBe(IssueStatus::Ignored)
        ->and($issue->effectiveStatus()->notifiable())->toBeFalse()
        // Ignored is not resolved, so its return is not a regression.
        ->and($issue->isRegression(Carbon::now()))->toBeFalse();
});

it('snoozes until a moment, then is open again on its own', function (): void {
    $issue = recordOne();

    app(IssueActions::class)->snooze($issue, Carbon::now()->addMinutes(30));
    expect($issue->effectiveStatus())->toBe(IssueStatus::Snoozed);

    Carbon::setTestNow(Carbon::now()->addHour());
    expect($issue->effectiveStatus())->toBe(IssueStatus::Open);
    Carbon::setTestNow();
});

it('resolves from the terminal, by a unique prefix of the fingerprint', function (): void {
    recordOne();

    $this->artisan('telemetry-insights:issue', [
        'action' => 'resolve',
        'fingerprint' => 'aaaa0000',
        '--release' => 'v3.0.0',
    ])->expectsOutputToContain('is now Resolved')->assertSuccessful();

    $issue = Issue::query()->firstWhere('fingerprint', 'aaaa00000001');
    expect($issue?->status)->toBe(IssueStatus::Resolved)
        ->and($issue?->resolved_in_release)->toBe('v3.0.0');
});

it('refuses an ambiguous prefix rather than resolving the wrong issue', function (): void {
    fakeBackends([
        exceptionStream('aaaa00000001', 'RedisException', 'a', 'trace0001', 300),
        exceptionStream('aaaa00000002', 'TypeError', 'b', 'trace0002', 300),
    ]);
    app(IssueLedger::class)->record(new RequestScope(period: '1h'));

    $this->artisan('telemetry-insights:issue', ['action' => 'resolve', 'fingerprint' => 'aaaa'])
        ->assertFailed();

    expect(Issue::query()->where('status', IssueStatus::Resolved)->count())->toBe(0);
});

it('rejects an action it does not know instead of doing nothing quietly', function (): void {
    recordOne();

    $this->artisan('telemetry-insights:issue', ['action' => 'delete', 'fingerprint' => 'aaaa00000001'])
        ->expectsOutputToContain('Unknown action')
        ->assertFailed();
});

it('lists the working list, open first', function (): void {
    recordOne();

    $this->artisan('telemetry-insights:issues')
        ->expectsOutputToContain('RedisException')
        ->assertSuccessful();
});
