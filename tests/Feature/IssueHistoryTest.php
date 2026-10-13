<?php

declare(strict_types=1);

use Cbox\TelemetryInsights\Issues\ActionSource;
use Cbox\TelemetryInsights\Issues\IssueAction;
use Cbox\TelemetryInsights\Issues\IssueActions;
use Cbox\TelemetryInsights\Issues\IssueKind;
use Cbox\TelemetryInsights\Issues\IssueLedger;
use Cbox\TelemetryInsights\Issues\IssueStatus;
use Cbox\TelemetryInsights\Models\Issue;
use Cbox\TelemetryUi\Http\Api\RequestScope;

/**
 * Every decision about an issue leaves a line in its history: what, who,
 * through which door, and why. Whatever the door.
 */
function historyIssue(): Issue
{
    $issue = new Issue;
    $issue->forceFill([
        'fingerprint' => 'aaaa00000001',
        'status' => IssueStatus::Open,
        'type' => 'RedisException',
        'service' => 'checkout',
    ])->save();

    return $issue;
}

it('records the opening, and the newest trace, when the scan first sees a group', function (): void {
    fakeBackends([
        exceptionStream('aaaa00000001', 'RedisException', 'refused', 'trace-old', 600),
        exceptionStream('aaaa00000001', 'RedisException', 'refused', 'trace-new', 60),
    ]);

    app(IssueLedger::class)->record(new RequestScope(period: '1h'));

    $issue = Issue::query()->sole();
    $event = $issue->events()->sole();

    expect($issue->kind())->toBe(IssueKind::Exception)
        ->and($issue->last_trace_id)->toBe('trace-new')
        ->and($event->action)->toBe(IssueAction::Opened)
        ->and($event->source)->toBe(ActionSource::Scan)
        ->and($event->describe())->toBe('Opened automatically by telemetry-insights');
});

it('records a regression the scan detected', function (): void {
    $issue = historyIssue();
    app(IssueActions::class)->resolve($issue);
    $issue->forceFill(['resolved_at' => now()->subHour()])->save();

    fakeBackends([exceptionStream('aaaa00000001', 'RedisException', 'refused', 'trace0001', 60)]);
    app(IssueLedger::class)->record(new RequestScope(period: '1h'));

    expect($issue->events()->get()->pluck('action')->all())->toBe([IssueAction::Resolved, IssueAction::Regressed]);
});

it('records a terminal decision with who made it and why', function (): void {
    $issue = historyIssue();

    $this->artisan('telemetry-insights:issue', [
        'action' => 'ignore', 'fingerprint' => 'aaaa', '--by' => 'sylvester', '--comment' => 'Expected during deploys',
    ])->assertSuccessful();

    $event = $issue->events()->sole();

    expect($event->source)->toBe(ActionSource::Console)
        ->and($event->actor)->toBe('sylvester')
        ->and($event->comment)->toBe('Expected during deploys')
        ->and($event->describe())->toBe('Ignored via console by sylvester');
});

it('records an API decision with its comment', function (): void {
    $issue = historyIssue();

    $this->postJson('/'.config('telemetry-ui.path', 'telemetry-ui').'/api/v2/insights/issues/aaaa00000001', [
        'action' => 'resolve', 'by' => 'sylvester', 'comment' => 'Retry added',
    ])->assertOk()->assertJsonPath('issue.kind', 'exception');

    $event = $issue->events()->sole();

    expect($event->source)->toBe(ActionSource::Api)
        ->and($event->action)->toBe(IssueAction::Resolved)
        ->and($event->comment)->toBe('Retry added')
        ->and($issue->fresh()?->resolved_by)->toBe('sylvester');
});

it('says "via code" when the host calls the service without an attribution', function (): void {
    $issue = historyIssue();

    app(IssueActions::class)->assign($issue, 'sylvester');

    expect($issue->events()->sole()->describe())->toBe('Assigned via code');
});
