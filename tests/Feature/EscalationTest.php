<?php

declare(strict_types=1);

use Cbox\TelemetryInsights\Issues\ActionSource;
use Cbox\TelemetryInsights\Issues\ChangeKind;
use Cbox\TelemetryInsights\Issues\IssueAction;
use Cbox\TelemetryInsights\Issues\IssueActions;
use Cbox\TelemetryInsights\Issues\IssueKind;
use Cbox\TelemetryInsights\Issues\IssueLedger;
use Cbox\TelemetryInsights\Issues\IssueStatus;
use Cbox\TelemetryInsights\Issues\StaleIssues;
use Cbox\TelemetryInsights\Mcp\Tools\ArchiveIssueTool;
use Cbox\TelemetryInsights\Models\Issue;
use Cbox\TelemetryInsights\Scan\Scanner;
use Cbox\TelemetryUi\Http\Api\RequestScope;
use Cbox\TelemetryUi\Mcp\Servers\TelemetryServer;
use Illuminate\Support\Carbon;

/**
 * Archive until escalating, and resolving what has gone quiet: two ways of
 * keeping the working list to what is happening, each with a way back.
 */
function escalatingIssue(IssueKind $kind = IssueKind::Exception, string $fingerprint = 'aaaa00000001'): Issue
{
    $issue = new Issue;
    $issue->forceFill([
        'fingerprint' => $fingerprint,
        'kind' => $kind,
        'status' => IssueStatus::Open,
        'type' => 'RedisException',
        'message' => 'Connection refused',
        'service' => 'checkout',
        'first_recorded_at' => now()->subDays(10),
        'last_seen_at' => now()->subHour(),
    ])->save();

    return $issue;
}

/**
 * A week of a group that throws twice an hour, plus sixty times in one
 * hour every night — the shape a failing nightly job makes.
 *
 * @return list<array<string, mixed>>
 */
function noisyWeek(): array
{
    $streams = [];

    for ($hour = 1; $hour <= 168; $hour++) {
        $streams[] = exceptionStream('aaaa00000001', 'RedisException', 'refused', 'trace'.$hour, $hour * 3600 + 600, $hour % 24 === 2 ? 60 : 2);
    }

    return $streams;
}

/**
 * @param  list<int>  $perHour  occurrences in the last hour, the hour before, …
 */
function lastHours(array $perHour): void
{
    $streams = [];

    foreach ($perHour as $hoursAgo => $count) {
        if ($count > 0) {
            $streams[] = exceptionStream('aaaa00000001', 'RedisException', 'refused', 'trace-now-'.$hoursAgo, $hoursAgo * 3600 + 1200, $count);
        }
    }

    fakeBackends($streams);
}

function archived(): Issue
{
    $issue = escalatingIssue();
    fakeBackends(noisyWeek());

    return app(IssueActions::class)->archive($issue);
}

it('learns a spike limit from a normal hour and a burst limit from the worst one', function (): void {
    $issue = archived();
    $event = $issue->events()->sole();

    expect($issue->status)->toBe(IssueStatus::Archived)
        ->and($issue->escalation_spike_limit)->toBe(10)   // 2/h × 3, floored at 10
        ->and($issue->escalation_burst_limit)->toBe(90)   // 60 × 1.5
        ->and($event->action)->toBe(IssueAction::Archived)
        ->and((string) $event->comment)->toContain('scalates above 10 an hour for 2 hours running, or 90 in any one hour');
});

it('stays archived through its usual nightly burst', function (): void {
    archived();
    lastHours([60, 0]);

    $changes = app(IssueLedger::class)->record(new RequestScope(period: '1h'));

    expect($changes[0]->kind)->toBe(ChangeKind::Recurring)
        ->and(Issue::query()->sole()->status)->toBe(IssueStatus::Archived);
});

it('reopens on a burst beyond anything it did last week', function (): void {
    archived();
    lastHours([120]);

    $changes = app(IssueLedger::class)->record(new RequestScope(period: '1h'));
    $issue = Issue::query()->sole();
    $event = $issue->events()->get()->last();

    expect($changes[0]->kind)->toBe(ChangeKind::Escalated)
        ->and($changes[0]->kind->isNews())->toBeTrue()
        ->and($issue->status)->toBe(IssueStatus::Open)
        ->and($event?->action)->toBe(IssueAction::Escalated)
        ->and($event?->source)->toBe(ActionSource::Scan)
        ->and($event?->comment)->toContain('over its burst limit of 90');
});

it('reopens on a sustained rise above a normal hour', function (): void {
    archived();
    lastHours([20, 20]);

    expect(app(IssueLedger::class)->record(new RequestScope(period: '1h'))[0]->kind)->toBe(ChangeKind::Escalated);
});

it('does not reopen on one elevated hour', function (): void {
    archived();
    lastHours([20, 0]);

    expect(app(IssueLedger::class)->record(new RequestScope(period: '1h'))[0]->kind)->toBe(ChangeKind::Recurring);
});

it('announces an escalation through the scan', function (): void {
    archived();
    lastHours([120]);

    $notifier = $this->fakeNotifications();
    $result = app(Scanner::class)->scan(new RequestScope(period: '1h'));

    expect($result->escalated)->toBe(1);
    $notifier->assertSent('Escalating: RedisException');
});

it('falls back to floor limits when the store will not answer, and says so', function (): void {
    $issue = escalatingIssue();

    $archived = app(IssueActions::class)->archive($issue);

    expect($archived->escalation_spike_limit)->toBe(10)
        ->and($archived->escalation_burst_limit)->toBe(10)
        ->and($archived->events()->sole()->comment)->toContain('the store did not answer');
});

it('refuses to archive a scheduled-task issue', function (): void {
    escalatingIssue(IssueKind::TaskMissed);

    TelemetryServer::tool(ArchiveIssueTool::class, ['fingerprint' => 'aaaa00000001'])->assertHasErrors(['Only error groups']);

    $this->postJson('/'.config('telemetry-ui.path', 'telemetry-ui').'/api/v2/insights/issues/aaaa00000001', ['action' => 'archive'])
        ->assertStatus(422);
});

it('archives through the agent tool, the API and the console', function (): void {
    fakeBackends(noisyWeek());
    $issue = escalatingIssue();

    TelemetryServer::tool(ArchiveIssueTool::class, ['fingerprint' => 'aaaa00000001', 'comment' => 'Known flake'])
        ->assertOk()
        ->assertSee('Status is now archived until escalating');

    expect($issue->events()->sole()->source)->toBe(ActionSource::Agent);

    app(IssueActions::class)->reopen($issue);
    $this->postJson('/'.config('telemetry-ui.path', 'telemetry-ui').'/api/v2/insights/issues/aaaa00000001', ['action' => 'archive'])
        ->assertOk()
        ->assertJsonPath('issue.status', 'archived')
        ->assertJsonPath('issue.escalation.burstLimit', 90);

    app(IssueActions::class)->reopen($issue);
    $this->artisan('telemetry-insights:issue', ['action' => 'archive', 'fingerprint' => 'aaaa'])
        ->expectsOutputToContain('reopens on its own if it escalates')
        ->assertSuccessful();
});

it('resolves an error group that has been quiet for the configured days, and brings it back as a regression', function (): void {
    $issue = escalatingIssue();
    $issue->forceFill(['last_seen_at' => Carbon::now()->subDays(20)])->save();
    $recent = escalatingIssue(fingerprint: 'bbbb00000002');
    $recent->forceFill(['last_seen_at' => Carbon::now()->subDays(3)])->save();

    $resolved = app(StaleIssues::class)->resolve(new RequestScope(period: '1h'));

    expect($resolved)->toHaveCount(1)
        ->and($issue->fresh()?->status)->toBe(IssueStatus::Resolved)
        ->and($recent->fresh()?->status)->toBe(IssueStatus::Open);

    $event = $issue->events()->sole();
    expect($event->source)->toBe(ActionSource::Scan)
        ->and($event->comment)->toBe('No occurrence for 14 days.');

    // It fires again after the resolve.
    $issue->forceFill(['resolved_at' => Carbon::now()->subHour()])->save();
    fakeBackends([exceptionStream('aaaa00000001', 'RedisException', 'refused', 'trace0001', 60)]);

    expect(app(IssueLedger::class)->record(new RequestScope(period: '1h'))[0]->kind)->toBe(ChangeKind::Regression);
});

it('leaves quiet issues alone when stale resolving is off, or when someone decided about them', function (): void {
    $issue = escalatingIssue();
    $issue->forceFill(['last_seen_at' => Carbon::now()->subDays(40), 'status' => IssueStatus::Ignored])->save();

    expect(app(StaleIssues::class)->resolve(new RequestScope(period: '1h')))->toBe([]);

    $issue->forceFill(['status' => IssueStatus::Open])->save();
    config()->set('telemetry-insights.stale.enabled', false);

    expect(app(StaleIssues::class)->resolve(new RequestScope(period: '1h')))->toBe([]);

    config()->set('telemetry-insights.stale.enabled', true);
    config()->set('telemetry-insights.stale.resolve_after_days', 60);

    expect(app(StaleIssues::class)->resolve(new RequestScope(period: '1h')))->toBe([]);
});
