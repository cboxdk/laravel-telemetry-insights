<?php

declare(strict_types=1);

use Cbox\TelemetryInsights\Issues\IncidentStatus;
use Cbox\TelemetryInsights\Issues\IssueKind;
use Cbox\TelemetryInsights\Issues\IssueStatus;
use Cbox\TelemetryInsights\Models\Incident;
use Cbox\TelemetryInsights\Models\Issue;
use Cbox\TelemetryInsights\Ui\Panels\IncidentsTable;
use Cbox\TelemetryInsights\Ui\Panels\IssuesTable;
use Cbox\TelemetryUi\Http\Api\RequestScope;
use Illuminate\Foundation\Auth\User;

/**
 * The dashboard's tenancy lock applies to the ledger on every surface: the
 * panel and the HTTP API, as well as the agent tools. Failing closed.
 */
function lockedIssue(string $fingerprint, string $service, ?string $environment = null): Issue
{
    $issue = new Issue;
    $issue->forceFill([
        'fingerprint' => $fingerprint,
        'status' => IssueStatus::Open,
        'type' => 'RuntimeException',
        'service' => $service,
        'environment' => $environment,
        'last_seen_at' => now(),
    ])->save();

    return $issue;
}

function lockTo(array $lock): void
{
    config()->set('telemetry-ui.scope.lock', $lock);
    // The lock is resolved once per request; this is a new one.
    app()->forgetScopedInstances();
}

function apiUrl(string $path): string
{
    return '/'.config('telemetry-ui.path', 'telemetry-ui').'/api/v2/insights/'.$path;
}

beforeEach(function (): void {
    lockedIssue('aaaa00000001', 'checkout');
    lockedIssue('bbbb00000002', 'billing', 'production');
});

it('lists only the services the viewer is locked to over the API', function (): void {
    lockTo(['services' => ['billing']]);

    $this->getJson(apiUrl('issues'))
        ->assertOk()
        ->assertJsonCount(1, 'issues')
        ->assertJsonPath('issues.0.fingerprint', 'bbbb00000002');
});

it('lists nothing to a viewer locked to nothing', function (): void {
    lockTo(['services' => []]);

    $this->getJson(apiUrl('issues'))->assertOk()->assertJsonCount(0, 'issues');
});

it('hides issues with no environment from a viewer whose environments are locked', function (): void {
    lockTo(['environments' => ['production']]);

    $this->getJson(apiUrl('issues'))->assertOk()->assertJsonCount(1, 'issues')->assertJsonPath('issues.0.fingerprint', 'bbbb00000002');
});

it('will not change an issue outside the lock — it reads as not there', function (): void {
    lockTo(['services' => ['billing']]);

    $this->postJson(apiUrl('issues/aaaa00000001'), ['action' => 'resolve'])->assertNotFound();

    expect(Issue::query()->where('fingerprint', 'aaaa00000001')->sole()->status)->toBe(IssueStatus::Open);
});

it('renders only the locked services in the issues panel', function (): void {
    lockTo(['services' => ['checkout']]);

    $rows = (new IssuesTable(new RequestScope(period: '24h')))->data()['rows'];

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['service']['v'])->toBe('checkout');
});

it('shows everything when nothing is locked', function (): void {
    $this->getJson(apiUrl('issues'))->assertOk()->assertJsonCount(2, 'issues');
});

it('will not let the API claim to be the scan, and records the signed-in user', function (): void {
    $this->postJson(apiUrl('issues/aaaa00000001'), ['action' => 'resolve', 'by' => 'Telemetry-Insights'])->assertStatus(422);

    $user = new User;
    $user->forceFill(['id' => 3, 'email' => 'real@example.com']);

    $this->actingAs($user)->postJson(apiUrl('issues/aaaa00000001'), ['action' => 'resolve', 'by' => 'someone-else'])->assertOk();

    expect(Issue::query()->where('fingerprint', 'aaaa00000001')->sole()->events()->sole()->actor)->toBe('real@example.com');

    $this->artisan('telemetry-insights:issue', ['action' => 'reopen', 'fingerprint' => 'aaaa00000001', '--by' => 'telemetry-insights'])->assertFailed();
});

it('filters by kind over the API, with the scheduled alias, and an unknown kind matches nothing', function (): void {
    lockedIssue('cccc00000003', 'checkout')->forceFill(['kind' => IssueKind::TaskMissed])->save();

    $this->getJson(apiUrl('issues?kind=scheduled'))->assertOk()->assertJsonCount(1, 'issues')->assertJsonPath('issues.0.fingerprint', 'cccc00000003');
    $this->getJson(apiUrl('issues?kind=exception'))->assertOk()->assertJsonCount(2, 'issues');
    $this->getJson(apiUrl('issues?kind=nonsense'))->assertOk()->assertJsonCount(0, 'issues');
});

it('applies the lock to incidents too, failing closed', function (): void {
    $incident = static fn (string $signature, array $services) => Incident::create([
        'signature' => $signature,
        'status' => IncidentStatus::Open,
        'title' => 'burst',
        'onset_at' => now()->subHour(),
        'fingerprints' => ['aaaa00000001'],
        'services' => $services,
    ]);

    $incident('aaa111', ['billing']);
    $incident('bbb222', ['billing', 'checkout']);

    lockTo(['services' => ['billing']]);

    $this->getJson(apiUrl('incidents'))->assertOk()->assertJsonCount(1, 'incidents')->assertJsonPath('incidents.0.signature', 'aaa111');
    $this->postJson(apiUrl('incidents/bbb222'), ['action' => 'resolve'])->assertNotFound();
    expect((new IncidentsTable(new RequestScope(period: '24h')))->data()['rows'])->toHaveCount(1);

    lockTo(['environments' => ['production']]);

    $this->getJson(apiUrl('incidents'))->assertOk()->assertJsonCount(0, 'incidents');
});
