<?php

declare(strict_types=1);

use Cbox\TelemetryInsights\Issues\ActionSource;
use Cbox\TelemetryInsights\Issues\IssueAction;
use Cbox\TelemetryInsights\Issues\IssueKind;
use Cbox\TelemetryInsights\Issues\IssueStatus;
use Cbox\TelemetryInsights\Mcp\Tools\IgnoreIssueTool;
use Cbox\TelemetryInsights\Mcp\Tools\ListIssuesTool;
use Cbox\TelemetryInsights\Mcp\Tools\ReopenIssueTool;
use Cbox\TelemetryInsights\Mcp\Tools\ResolveIssueTool;
use Cbox\TelemetryInsights\Mcp\Tools\ShowIssueTool;
use Cbox\TelemetryInsights\Models\Issue;
use Cbox\TelemetryUi\Mcp\Servers\TelemetryServer;
use Cbox\TelemetryUi\TelemetryUiManager;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * The ledger as agent tools, on the dashboard's own MCP server. What has to
 * hold: the viewer's tenancy lock applies, the output is compact, and every
 * change an agent makes is in the history as made via agent.
 */

/**
 * @param  array<string, mixed>  $attributes
 */
function ledgerIssue(string $fingerprint, array $attributes = []): Issue
{
    $issue = new Issue;
    $issue->forceFill([
        'fingerprint' => $fingerprint,
        'kind' => IssueKind::Exception,
        'status' => IssueStatus::Open,
        'type' => 'RedisException',
        'message' => 'Connection refused',
        'service' => 'checkout',
        'first_recorded_at' => Carbon::now()->subDay(),
        'last_seen_at' => Carbon::now()->subMinutes(5),
        ...$attributes,
    ])->save();

    return $issue;
}

function agentUser(): User
{
    $user = new User;
    $user->forceFill(['id' => 7, 'email' => 'agent-owner@example.com']);

    return $user;
}

it('registers the ledger tools on the dashboard MCP server', function (): void {
    expect(app(TelemetryUiManager::class)->mcpTools())->toContain(
        ListIssuesTool::class,
        ShowIssueTool::class,
        ResolveIssueTool::class,
        IgnoreIssueTool::class,
        ReopenIssueTool::class,
    );

    TelemetryServer::tool(ListIssuesTool::class)->assertOk();
});

it('lists open issues first, compactly, with a total and the next offset', function (): void {
    ledgerIssue('aaaa00000001', ['last_seen_at' => Carbon::now()->subMinutes(1)]);
    ledgerIssue('bbbb00000002', ['status' => IssueStatus::Resolved, 'type' => 'TypeError']);
    ledgerIssue('cccc00000003', [
        'kind' => IssueKind::TaskMissed, 'type' => 'Scheduled task missed', 'subject' => 'reports:nightly',
        'environment' => 'production', 'message' => 'reports:nightly was due', 'last_seen_at' => Carbon::now()->subMinutes(2),
    ]);

    TelemetryServer::tool(ListIssuesTool::class, ['limit' => 2])
        ->assertOk()
        ->assertSee('"total":3')
        ->assertSee('"next_offset":2')
        ->assertSee('"fingerprint":"aaaa00000001"')
        ->assertSee('"title":"Scheduled task missed: reports:nightly"')
        ->assertDontSee('bbbb00000002')
        // Empty keys are dropped, not sent as null.
        ->assertDontSee('"assignee"')
        ->assertDontSee('null');

    TelemetryServer::tool(ListIssuesTool::class, ['limit' => 2, 'offset' => 2])
        ->assertOk()
        ->assertSee('bbbb00000002')
        ->assertDontSee('next_offset');
});

it('filters by effective status, kind and service', function (): void {
    ledgerIssue('aaaa00000001');
    ledgerIssue('bbbb00000002', ['status' => IssueStatus::Snoozed, 'snoozed_until' => Carbon::now()->subHour()]);
    ledgerIssue('cccc00000003', ['status' => IssueStatus::Snoozed, 'snoozed_until' => Carbon::now()->addDay()]);
    ledgerIssue('dddd00000004', ['kind' => IssueKind::SchedulerStopped, 'service' => 'billing']);

    // A lapsed snooze is open.
    TelemetryServer::tool(ListIssuesTool::class, ['status' => 'open'])
        ->assertSee('"total":3')
        ->assertSee('bbbb00000002')
        ->assertDontSee('cccc00000003');

    TelemetryServer::tool(ListIssuesTool::class, ['status' => 'snoozed'])->assertSee('"total":1')->assertSee('cccc00000003');
    TelemetryServer::tool(ListIssuesTool::class, ['kind' => 'scheduled'])->assertSee('"total":1')->assertSee('dddd00000004');
    TelemetryServer::tool(ListIssuesTool::class, ['kind' => 'nonsense'])->assertSee('"total":0');
    TelemetryServer::tool(ListIssuesTool::class, ['service' => 'billing'])->assertSee('"total":1');
});

it('honours the viewer tenancy lock, and fails closed', function (): void {
    ledgerIssue('aaaa00000001');
    ledgerIssue('bbbb00000002', ['service' => 'billing']);

    config()->set('telemetry-ui.scope.lock', ['services' => ['billing']]);

    TelemetryServer::tool(ListIssuesTool::class)->assertSee('"total":1')->assertSee('bbbb00000002')->assertDontSee('aaaa00000001');
    TelemetryServer::tool(ShowIssueTool::class, ['fingerprint' => 'aaaa00000001'])->assertHasErrors();
    TelemetryServer::tool(ResolveIssueTool::class, ['fingerprint' => 'aaaa00000001'])->assertHasErrors();

    config()->set('telemetry-ui.scope.lock', ['services' => []]);
    // The lock is resolved once per request (a scoped binding); this is a
    // new "request".
    app()->forgetScopedInstances();

    TelemetryServer::tool(ListIssuesTool::class)->assertSee('"total":0');
});

it('shows an issue as Markdown with the last day read live, and its history', function (): void {
    $issue = ledgerIssue('aaaa00000001', ['last_trace_id' => 'oldtrace']);

    fakeBackends([
        ['stream' => ['service_name' => 'checkout', 'exception_group' => 'aaaa00000001', 'exception_type' => 'RedisException',
            'exception_message' => 'Connection refused', 'trace_id' => 'trace0001', 'user_id' => '41'],
            'values' => [[(string) ((time() - 600) * 1_000_000_000), 'exception']]],
        ['stream' => ['service_name' => 'checkout', 'exception_group' => 'aaaa00000001', 'exception_type' => 'RedisException',
            'exception_message' => 'Connection refused', 'trace_id' => 'trace0002', 'user_id' => '42'],
            'values' => [[(string) ((time() - 60) * 1_000_000_000), 'exception'], [(string) ((time() - 120) * 1_000_000_000), 'exception']]],
    ]);

    TelemetryServer::actingAs(agentUser())->tool(ResolveIssueTool::class, [
        'fingerprint' => 'aaaa', 'comment' => 'Fixed by retrying the connection', 'release' => 'v2.4.1',
    ])->assertOk();

    TelemetryServer::tool(ShowIssueTool::class, ['fingerprint' => 'aaaa0000'])
        ->assertOk()
        ->assertSee('# RedisException')
        ->assertSee('- **Fingerprint**: `aaaa00000001`')
        ->assertSee('- **Occurrences (24h)**: 3')
        ->assertSee('- **Affected users (24h)**: 2')
        ->assertSee('- **Latest trace**: `trace0002`')
        ->assertSee('in v2.4.1')
        ->assertSee('## History')
        ->assertSee('Resolved via agent by agent-owner@example.com — "Fixed by retrying the connection"');

    expect($issue->fresh()?->resolved_by)->toBe('agent-owner@example.com');
});

it('records resolve, ignore and reopen in the history as made via agent', function (): void {
    $issue = ledgerIssue('aaaa00000001');

    TelemetryServer::tool(ResolveIssueTool::class, ['fingerprint' => 'aaaa00000001'])
        ->assertOk()
        ->assertSee('Resolved `aaaa00000001` (RedisException). Status is now resolved; recorded in its history as made via agent.');
    TelemetryServer::tool(IgnoreIssueTool::class, ['fingerprint' => 'aaaa00000001', 'comment' => 'Expected during deploys'])
        ->assertOk()
        ->assertSee('Status is now ignored');
    TelemetryServer::tool(ReopenIssueTool::class, ['fingerprint' => 'aaaa00000001'])
        ->assertOk()
        ->assertSee('Reopened');

    $events = $issue->events()->get();

    expect($events->pluck('action')->all())->toBe([IssueAction::Resolved, IssueAction::Ignored, IssueAction::Reopened])
        ->and($events->pluck('source')->unique()->all())->toBe([ActionSource::Agent])
        ->and($events[1]?->comment)->toBe('Expected during deploys')
        ->and($issue->fresh()?->status)->toBe(IssueStatus::Open);
});

it('refuses a change to a user without the manage ability', function (): void {
    $issue = ledgerIssue('aaaa00000001');
    Gate::define('manageTelemetryUi', static fn (?object $user = null): bool => false);

    TelemetryServer::actingAs(agentUser())->tool(ResolveIssueTool::class, ['fingerprint' => 'aaaa00000001'])
        ->assertHasErrors(['manageTelemetryUi']);

    expect($issue->fresh()?->status)->toBe(IssueStatus::Open)
        ->and($issue->events()->count())->toBe(0);
});

it('refuses an ambiguous fingerprint prefix rather than guessing', function (): void {
    ledgerIssue('aaaa00000001');
    ledgerIssue('aaaa00000002');

    TelemetryServer::tool(ResolveIssueTool::class, ['fingerprint' => 'aaaa'])->assertHasErrors(['ambiguous']);
});

it('offers only the read tools when agent writes are off', function (): void {
    config()->set('telemetry-insights.mcp.writes', false);
    ledgerIssue('aaaa00000001');

    TelemetryServer::tool(ResolveIssueTool::class, ['fingerprint' => 'aaaa00000001'])->assertNotRegistered();
    TelemetryServer::tool(ListIssuesTool::class)->assertOk();
});
