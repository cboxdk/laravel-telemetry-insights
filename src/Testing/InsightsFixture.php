<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Testing;

use Cbox\TelemetryInsights\Correlate\CauseKind;
use Cbox\TelemetryInsights\Correlate\Confidence;
use Cbox\TelemetryInsights\Issues\IncidentStatus;
use Cbox\TelemetryInsights\Issues\IssueStatus;
use Cbox\TelemetryInsights\Models\Incident;
use Cbox\TelemetryInsights\Models\Issue;
use Illuminate\Support\Carbon;

/**
 * A believable set of issues and incidents, written to the database.
 *
 * This package is the stateful half of the dashboard: its screens read its
 * own tables rather than a metrics backend, so the fixture BACKENDS that
 * laravel-telemetry-ui ships are necessary but not sufficient — a browser
 * pointed at /p/issues with an empty table renders the empty state, which
 * is a fine screen and not the one the documentation is about.
 *
 * Deterministic, so a screenshot taken today matches one taken later, and
 * plausible, because the point of both the browser test and the screenshot
 * is to show what a real installation looks like. The ages are relative to
 * now so "last seen 4 minutes ago" reads correctly whenever it runs.
 */
final class InsightsFixture
{
    /**
     * @return array{issues: int, incidents: int}
     */
    public static function seed(): array
    {
        $now = Carbon::now();

        $issues = [
            [
                'fingerprint' => 'a1c4f2e8b9',
                'type' => 'Illuminate\\Database\\QueryException',
                'message' => 'SQLSTATE[HY000] [2002] Connection refused (Connection: mysql)',
                'service' => 'checkout',
                'status' => IssueStatus::Open,
                'first' => $now->copy()->subDays(2)->subHours(3),
                'last' => $now->copy()->subMinutes(4),
            ],
            [
                'fingerprint' => 'd7b3019fa2',
                'type' => 'GuzzleHttp\\Exception\\ConnectException',
                'message' => 'cURL error 28: Operation timed out after 3000 milliseconds',
                'service' => 'billing',
                'status' => IssueStatus::Open,
                'first' => $now->copy()->subDays(9),
                'last' => $now->copy()->subMinutes(21),
            ],
            [
                'fingerprint' => '5f0ac6d114',
                'type' => 'Illuminate\\Validation\\ValidationException',
                'message' => 'The given data was invalid.',
                'service' => 'identity',
                'status' => IssueStatus::Snoozed,
                'first' => $now->copy()->subDays(14),
                'last' => $now->copy()->subHours(6),
                'snoozed' => $now->copy()->addDays(3),
            ],
            [
                'fingerprint' => '9e21b7c305',
                'type' => 'RuntimeException',
                'message' => 'Queue worker exceeded memory limit while processing App\\Jobs\\RebuildIndex',
                'service' => 'search',
                'status' => IssueStatus::Resolved,
                'first' => $now->copy()->subDays(21),
                'last' => $now->copy()->subDays(6),
                'resolved' => $now->copy()->subDays(5),
                'release' => 'v2026.9.3',
            ],
            [
                'fingerprint' => '3ca8e5019d',
                'type' => 'Illuminate\\Http\\Client\\RequestException',
                'message' => 'HTTP request returned status code 502 for https://api.upstream.test/v1/rates',
                'service' => 'catalogue',
                'status' => IssueStatus::Open,
                'first' => $now->copy()->subHours(5),
                'last' => $now->copy()->subMinutes(2),
            ],
        ];

        foreach ($issues as $issue) {
            Issue::query()->create([
                'fingerprint' => $issue['fingerprint'],
                'status' => $issue['status'],
                'type' => $issue['type'],
                'message' => $issue['message'],
                'service' => $issue['service'],
                'first_recorded_at' => $issue['first'],
                'last_seen_at' => $issue['last'],
                'snoozed_until' => $issue['snoozed'] ?? null,
                'resolved_at' => $issue['resolved'] ?? null,
                'resolved_by' => isset($issue['resolved']) ? 'sylvester' : null,
                'resolved_in_release' => $issue['release'] ?? null,
            ]);
        }

        $incidents = [
            [
                'signature' => 'dep:mysql:checkout',
                'status' => IncidentStatus::Open,
                'title' => 'Three error groups across two services, all waiting on mysql',
                'onset' => $now->copy()->subMinutes(26),
                'kind' => CauseKind::Dependency,
                'label' => 'mysql · db.internal:3306',
                'confidence' => Confidence::High,
                'evidence' => 'Every affected trace spent >2.9s in a db.client span before failing; the dependency\'s own error rate went from 0 to 94% at onset.',
                'fingerprints' => ['a1c4f2e8b9', '3ca8e5019d'],
                'services' => ['checkout', 'catalogue'],
                'trace' => 'b4f19c02d7e8a35619fb2c4d0a7e88c1',
                'groups' => 3,
                'occurrences' => 412,
            ],
            [
                'signature' => 'deploy:v2026.9.7',
                'status' => IncidentStatus::Acknowledged,
                'title' => 'Error rate stepped up within a minute of v2026.9.7',
                'onset' => $now->copy()->subHours(3)->subMinutes(12),
                'kind' => CauseKind::Deploy,
                'label' => 'v2026.9.7 · billing',
                'confidence' => Confidence::Medium,
                'evidence' => 'Onset is 48 seconds after the deploy annotation. No dependency changed state in the window.',
                'fingerprints' => ['d7b3019fa2'],
                'services' => ['billing'],
                'trace' => null,
                'groups' => 1,
                'occurrences' => 96,
            ],
        ];

        foreach ($incidents as $incident) {
            Incident::query()->create([
                'signature' => $incident['signature'],
                'status' => $incident['status'],
                'title' => $incident['title'],
                'onset_at' => $incident['onset'],
                'cause_kind' => $incident['kind'],
                'cause_label' => $incident['label'],
                'cause_evidence' => $incident['evidence'],
                'cause_confidence' => $incident['confidence'],
                'cause_trace_id' => $incident['trace'],
                'fingerprints' => $incident['fingerprints'],
                'services' => $incident['services'],
                // The table shows both, and leaving them at their zero
                // defaults printed "0 groups" next to an incident whose own
                // title says it spans three.
                'group_count' => $incident['groups'],
                'occurrences' => $incident['occurrences'],
                'acknowledged_at' => $incident['status'] === IncidentStatus::Acknowledged
                    ? $now->copy()->subHours(2)
                    : null,
                'acknowledged_by' => $incident['status'] === IncidentStatus::Acknowledged ? 'sylvester' : null,
            ]);
        }

        return ['issues' => count($issues), 'incidents' => count($incidents)];
    }
}
