<?php

declare(strict_types=1);

use Cbox\TelemetryInsights\Issues\IssueStatus;
use Cbox\TelemetryInsights\Models\Issue;
use Cbox\TelemetryInsights\Ui\Panels\IssuesTable;
use Cbox\TelemetryUi\Http\Api\RequestScope;

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
