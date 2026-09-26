<?php

declare(strict_types=1);

use Cbox\TelemetryInsights\Correlate\CauseKind;
use Cbox\TelemetryInsights\Correlate\Confidence;
use Cbox\TelemetryInsights\Issues\IncidentStatus;
use Cbox\TelemetryInsights\Issues\IssueStatus;
use Cbox\TelemetryInsights\Models\Incident;
use Cbox\TelemetryInsights\Models\Issue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * The write API exists because the pages this package adds to the dashboard
 * are read-only lists — a host that wants buttons builds them on these.
 * Reading takes the dashboard's view ability; changing anything takes the
 * separate manage ability, which is the part worth testing hardest.
 */
function insightsUrl(string $path): string
{
    return '/'.config('telemetry-ui.path', 'telemetry-ui').'/api/v2/insights/'.$path;
}

function anIssue(IssueStatus $status = IssueStatus::Open): Issue
{
    return Issue::create([
        'fingerprint' => 'aaaa00000001',
        'status' => $status,
        'type' => 'RedisException',
        'message' => 'Connection refused',
        'service' => 'checkout',
        'last_seen_at' => Carbon::now(),
    ]);
}

function anIncident(): Incident
{
    return Incident::create([
        'signature' => 'abc123def456',
        'status' => IncidentStatus::Open,
        'title' => 'redis cache-1:6379 — 9 error groups affected',
        'onset_at' => Carbon::now()->subHour(),
        'cause_kind' => CauseKind::Dependency,
        'cause_label' => 'redis cache-1:6379',
        'cause_evidence' => '100% of the affected groups called this cache.',
        'cause_confidence' => Confidence::High,
        'occurrences' => 412,
        'group_count' => 9,
        'fingerprints' => ['aaaa00000001'],
        'services' => ['checkout'],
    ]);
}

it('lists issues with their effective status', function (): void {
    anIssue(IssueStatus::Snoozed)->forceFill(['snoozed_until' => Carbon::now()->subHour()])->save();

    $this->getJson(insightsUrl('issues'))
        ->assertOk()
        // A lapsed snooze reads as open, the same as everywhere else.
        ->assertJsonPath('issues.0.status', 'open')
        ->assertJsonPath('issues.0.fingerprint', 'aaaa00000001')
        ->assertJsonPath('issues.0.service', 'checkout');
});

it('resolves an issue and records the release', function (): void {
    anIssue();

    $this->postJson(insightsUrl('issues/aaaa00000001'), ['action' => 'resolve', 'release' => 'v2.4.1', 'by' => 'sylvester'])
        ->assertOk()
        ->assertJsonPath('issue.status', 'resolved')
        ->assertJsonPath('issue.resolvedIn', 'v2.4.1');

    expect(Issue::query()->firstWhere('fingerprint', 'aaaa00000001')?->resolved_by)->toBe('sylvester');
});

it('snoozes for a while, and defaults to a day when told nothing', function (): void {
    anIssue();

    $this->postJson(insightsUrl('issues/aaaa00000001'), ['action' => 'snooze'])
        ->assertOk()
        ->assertJsonPath('issue.status', 'snoozed');

    $until = Issue::query()->firstWhere('fingerprint', 'aaaa00000001')?->snoozed_until;

    expect($until?->greaterThan(Carbon::now()->addHours(20)))->toBeTrue();
});

it('refuses a write to someone who may only look', function (): void {
    anIssue();
    Gate::define('manageTelemetryUi', static fn (?object $user = null): bool => false);

    $this->postJson(insightsUrl('issues/aaaa00000001'), ['action' => 'resolve'])
        ->assertForbidden()
        ->assertJsonPath('error.type', 'forbidden');

    expect(Issue::query()->firstWhere('fingerprint', 'aaaa00000001')?->status)->toBe(IssueStatus::Open);
});

it('404s a fingerprint nobody has recorded', function (): void {
    $this->postJson(insightsUrl('issues/ffff99999999'), ['action' => 'resolve'])
        ->assertNotFound()
        ->assertJsonPath('error.type', 'not_found');
});

it('rejects an action it does not know', function (): void {
    anIssue();

    $this->postJson(insightsUrl('issues/aaaa00000001'), ['action' => 'delete'])
        ->assertStatus(422)
        ->assertJsonPath('error.type', 'invalid');
});

it('requires an action rather than assuming one', function (): void {
    anIssue();

    $this->postJson(insightsUrl('issues/aaaa00000001'), [])->assertStatus(422);
});

it('serves an incident with its cause, and acknowledges it', function (): void {
    anIncident();

    $this->getJson(insightsUrl('incidents'))
        ->assertOk()
        ->assertJsonPath('incidents.0.cause.label', 'redis cache-1:6379')
        ->assertJsonPath('incidents.0.cause.confidence', 'high')
        ->assertJsonPath('incidents.0.groupCount', 9);

    $this->postJson(insightsUrl('incidents/abc123def456'), ['action' => 'acknowledge', 'by' => 'sylvester'])
        ->assertOk()
        ->assertJsonPath('incident.status', 'acknowledged')
        ->assertJsonPath('incident.acknowledgedBy', 'sylvester');
});

it('serves an incident with no established cause as a null cause', function (): void {
    Incident::create([
        'signature' => 'nocause00001',
        'status' => IncidentStatus::Open,
        'title' => '3 error groups started together',
        'onset_at' => Carbon::now(),
        'occurrences' => 9,
        'group_count' => 3,
        'fingerprints' => [],
        'services' => [],
    ]);

    $this->getJson(insightsUrl('incidents'))->assertOk()->assertJsonPath('incidents.0.cause', null);
});

it('is not swallowed by the dashboard SPA catch-all', function (): void {
    anIssue();

    // The dashboard serves its shell from `/{any?}` under the same prefix,
    // excluding `api/` with a negative lookahead. This package's routes sit
    // under `api/v2/insights`, so they survive that — and this test is here
    // to fail loudly if the dashboard ever narrows that exclusion.
    $response = $this->get(insightsUrl('issues'), ['Accept' => 'application/json']);

    expect($response->headers->get('content-type'))->toContain('application/json')
        ->and($response->json('issues'))->toBeArray();

    // And the shell is still served for a real page under the same prefix.
    $this->get('/'.config('telemetry-ui.path').'/p/incidents')->assertOk();
});
