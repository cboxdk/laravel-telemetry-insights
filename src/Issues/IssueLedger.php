<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Issues;

use Cbox\TelemetryInsights\Models\Issue;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Explore\ErrorExplorer;
use Cbox\TelemetryUi\Http\Api\RequestScope;
use Illuminate\Support\Carbon;

/**
 * Keeps the ledger of which error groups this install has seen.
 *
 * This is the whole reason the package has a database. The telemetry store
 * can tell you an exception happened; only a ledger can tell you it never
 * happened before, or that it is back after you called it fixed. "New" and
 * "regressed" are the two facts worth waking up for, and neither is
 * answerable without remembering something.
 */
class IssueLedger
{
    public function __construct(private readonly ErrorExplorer $errors) {}

    /**
     * Read the window, fold it into the ledger, and report what changed.
     *
     * @return list<IssueChange>
     */
    public function record(RequestScope $scope, int $limit = 500): array
    {
        try {
            $occurrences = $this->errors->occurrences($scope, $limit);
        } catch (SourceException) {
            return [];
        }

        /** @var array<string, array{type: string, message: string, service: string, count: int, latest: int}> $seen */
        $seen = [];

        foreach ($occurrences as $occurrence) {
            $fingerprint = $occurrence['group'];

            if ($fingerprint === '') {
                continue;
            }

            $existing = $seen[$fingerprint] ?? null;
            $seen[$fingerprint] = [
                'type' => $existing['type'] ?? $occurrence['type'],
                'message' => $existing['message'] ?? $occurrence['message'],
                'service' => $existing['service'] ?? $occurrence['service'],
                'count' => ($existing['count'] ?? 0) + 1,
                'latest' => max($existing['latest'] ?? 0, $occurrence['nano']),
            ];
        }

        $changes = [];

        foreach ($seen as $fingerprint => $data) {
            $changes[] = $this->fold($fingerprint, $data);
        }

        return $changes;
    }

    /**
     * @param  array{type: string, message: string, service: string, count: int, latest: int}  $data
     */
    private function fold(string $fingerprint, array $data): IssueChange
    {
        $lastSeen = Carbon::createFromTimestampMs(intdiv($data['latest'], 1_000_000));
        $issue = Issue::query()->firstWhere('fingerprint', $fingerprint);

        if (! $issue instanceof Issue) {
            $issue = new Issue;
            $issue->forceFill([
                'fingerprint' => $fingerprint,
                'status' => IssueStatus::Open,
                'type' => $data['type'],
                'message' => $data['message'],
                'service' => $data['service'],
                'first_recorded_at' => $lastSeen,
                'last_seen_at' => $lastSeen,
            ])->save();

            return new IssueChange($issue, ChangeKind::New, $data['count']);
        }

        $regressed = $issue->isRegression($lastSeen);

        $issue->forceFill([
            'last_seen_at' => $lastSeen,
            // A resolved issue that fired again is open again; nobody should
            // have to notice that by hand.
            'status' => $regressed ? IssueStatus::Open : $issue->status,
            'resolved_at' => $regressed ? null : $issue->resolved_at,
        ])->save();

        return new IssueChange($issue, $regressed ? ChangeKind::Regression : ChangeKind::Recurring, $data['count']);
    }
}
