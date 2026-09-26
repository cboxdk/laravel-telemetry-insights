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
 *
 * Reading and remembering are separate on purpose — see {@see preview()}.
 */
class IssueLedger
{
    public function __construct(private readonly ErrorExplorer $errors) {}

    /**
     * Read the window, fold it into the ledger, and report what changed.
     *
     * This *writes*: afterwards those fingerprints are known, so they are
     * never new again. Only the scan should call it.
     *
     * @return list<IssueChange>
     */
    public function record(RequestScope $scope, int $limit = 500): array
    {
        return $this->classify($scope, $limit, persist: true);
    }

    /**
     * The same answer, without remembering it.
     *
     * A digest is a read. If summarising the week also marked every
     * fingerprint as seen, running the digest before the scan would leave
     * the scan with nothing to announce — a report would quietly disarm the
     * alerting. So the digest previews, and only the scan records.
     *
     * @return list<IssueChange>
     */
    public function preview(RequestScope $scope, int $limit = 500): array
    {
        return $this->classify($scope, $limit, persist: false);
    }

    /**
     * @return list<IssueChange>
     */
    private function classify(RequestScope $scope, int $limit, bool $persist): array
    {
        try {
            $occurrences = $this->errors->occurrences($scope, $limit);
        } catch (SourceException) {
            return [];
        }

        $seen = $this->fold($occurrences);

        if ($seen === []) {
            return [];
        }

        // One query for the whole window rather than one per fingerprint: a
        // busy window holds hundreds of groups, and this runs every few
        // minutes.
        $known = Issue::query()
            ->whereIn('fingerprint', array_keys($seen))
            ->get()
            ->keyBy('fingerprint');

        $changes = [];

        foreach ($seen as $fingerprint => $data) {
            $lastSeen = Carbon::createFromTimestampMs(intdiv($data['latest'], 1_000_000));
            $existing = $known->get($fingerprint);

            $changes[] = $existing instanceof Issue
                ? $this->existing($existing, $data, $lastSeen, $persist)
                : $this->fresh((string) $fingerprint, $data, $lastSeen, $persist);
        }

        return $changes;
    }

    /**
     * Occurrences folded per fingerprint: how many, and the newest.
     *
     * @param  list<array{group: string, type: string, message: string, nano: int, traceId: string|null, service: string, user: string, frontend: bool, attributes: array<string, string>}>  $occurrences
     * @return array<string, array{type: string, message: string, service: string, count: int, latest: int}>
     */
    private function fold(array $occurrences): array
    {
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

        return $seen;
    }

    /**
     * @param  array{type: string, message: string, service: string, count: int, latest: int}  $data
     */
    private function fresh(string $fingerprint, array $data, Carbon $lastSeen, bool $persist): IssueChange
    {
        $issue = new Issue;
        $issue->forceFill([
            'fingerprint' => $fingerprint,
            'status' => IssueStatus::Open,
            'type' => $data['type'],
            'message' => $data['message'],
            'service' => $data['service'],
            'first_recorded_at' => $lastSeen,
            'last_seen_at' => $lastSeen,
        ]);

        if ($persist) {
            $issue->save();
        }

        return new IssueChange($issue, ChangeKind::New, $data['count']);
    }

    /**
     * @param  array{type: string, message: string, service: string, count: int, latest: int}  $data
     */
    private function existing(Issue $issue, array $data, Carbon $lastSeen, bool $persist): IssueChange
    {
        $regressed = $issue->isRegression($lastSeen);

        $issue->forceFill([
            'last_seen_at' => $lastSeen,
            // A resolved issue that fired again is open again; nobody should
            // have to notice that by hand.
            'status' => $regressed ? IssueStatus::Open : $issue->status,
            'resolved_at' => $regressed ? null : $issue->resolved_at,
        ]);

        if ($persist) {
            $issue->save();
        }

        return new IssueChange($issue, $regressed ? ChangeKind::Regression : ChangeKind::Recurring, $data['count']);
    }
}
