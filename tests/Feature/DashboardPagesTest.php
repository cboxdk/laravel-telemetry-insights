<?php

declare(strict_types=1);

use Cbox\TelemetryInsights\Correlate\CauseKind;
use Cbox\TelemetryInsights\Correlate\Confidence;
use Cbox\TelemetryInsights\Issues\IncidentStatus;
use Cbox\TelemetryInsights\Issues\IssueStatus;
use Cbox\TelemetryInsights\Models\Incident;
use Cbox\TelemetryInsights\Models\Issue;
use Cbox\TelemetryInsights\Ui\InsightsPages;
use Cbox\TelemetryInsights\Ui\Panels\IncidentsTable;
use Cbox\TelemetryInsights\Ui\Panels\IssuesTable;
use Cbox\TelemetryUi\Facades\TelemetryUi;
use Cbox\TelemetryUi\Http\Api\RequestScope;
use Illuminate\Support\Carbon;

/**
 * The soft half of the add-on: pages that appear in the dashboard when one
 * is served, and are simply absent when it is not.
 */
function render(string $panel): array
{
    return (new $panel(new RequestScope(period: '24h')))->data();
}

it('adds an Incidents and an Issues page to the dashboard', function (): void {
    expect(TelemetryUi::pages())->toHaveKeys(['incidents', 'issues'])
        ->and(TelemetryUi::panels('incidents'))->toContain(IncidentsTable::class)
        ->and(TelemetryUi::panels('issues'))->toContain(IssuesTable::class);
});

it('registers nothing when the dashboard is not served', function (): void {
    config()->set('telemetry-ui.enabled', false);

    expect(InsightsPages::dashboardPresent())->toBeFalse();
});

it('renders an incident with its cause and confidence', function (): void {
    Incident::create([
        'signature' => 'abc123',
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

    $payload = render(IncidentsTable::class);
    $row = $payload['rows'][0];

    expect($payload['kind'])->toBe('table')
        ->and($row['cause']['v'])->toBe('redis cache-1:6379')
        ->and($row['cause']['sub'])->toBe('high')
        ->and($row['groups']['v'])->toBe(9)
        ->and($row['status']['v'])->toBe('Open')
        ->and($row['status']['tone'])->toBe('danger');
});

it('renders an incident whose cause was never established', function (): void {
    Incident::create([
        'signature' => 'abc124',
        'status' => IncidentStatus::Open,
        'title' => '3 error groups started together',
        'onset_at' => Carbon::now()->subHour(),
        'occurrences' => 12,
        'group_count' => 3,
        'fingerprints' => [],
        'services' => [],
    ]);

    $row = render(IncidentsTable::class)['rows'][0];

    expect($row['cause']['v'])->toBe('—')
        ->and($row['cause']['sub'])->toBe('');
});

it('puts open issues above the rest', function (): void {
    Issue::create([
        'fingerprint' => 'resolved0001',
        'status' => IssueStatus::Resolved,
        'type' => 'OldException',
        'last_seen_at' => Carbon::now(),
    ]);
    Issue::create([
        'fingerprint' => 'open00000001',
        'status' => IssueStatus::Open,
        'type' => 'LiveException',
        'service' => 'checkout',
        'last_seen_at' => Carbon::now()->subHour(),
    ]);

    $rows = render(IssuesTable::class)['rows'];

    expect($rows[0]['type']['v'])->toBe('LiveException')
        ->and($rows[0]['status']['v'])->toBe('Open')
        ->and($rows[1]['type']['v'])->toBe('OldException');
});

it('says what to do when nothing has been recorded yet', function (): void {
    expect(render(IssuesTable::class)['empty'])->toContain('telemetry-insights:scan')
        ->and(render(IncidentsTable::class)['rows'])->toBe([]);
});
