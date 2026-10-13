<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Issues;

use Cbox\TelemetryInsights\Models\Issue;
use Cbox\TelemetryInsights\Schedule\ScheduleMonitor;
use Cbox\TelemetryInsights\Schedule\ScheduleProblem;
use Cbox\TelemetryInsights\Schedule\ScheduleReading;
use Cbox\TelemetryUi\Http\Api\RequestScope;
use Illuminate\Support\Carbon;

/**
 * The ledger for scheduled-task issues.
 *
 * An error group is an occurrence: it happened, and only a person can say
 * it is fixed. A missed, overrunning or failing task is a condition: it
 * holds until the task runs again, so the scan opens the issue while the
 * condition holds and resolves it when it clears — attributed to the scan
 * in the issue's history, so nobody mistakes it for a person's call.
 *
 * What counts as "back again":
 *
 * - after the scan resolved it, any recurrence — the condition cleared in
 *   between, so this is a new episode by definition;
 * - after a PERSON resolved it, only an episode that began after they did
 *   ({@see ScheduleProblem::$since}). Resolving a task that is still behind
 *   says "I know"; it should not be contradicted on the next pass.
 *
 * - after a person resolved a FAILING task, also any failure after the
 *   resolve: it was called fixed and failed again.
 *
 * Ignored and snoozed issues are kept current but never reopened or
 * resolved by the scan.
 */
class ScheduleLedger
{
    public function __construct(
        private readonly ScheduleMonitor $monitor,
        private readonly IssueActions $actions,
    ) {}

    /**
     * Read the schedule, fold it into the ledger, resolve what cleared, and
     * report what changed. This writes; only the scan should call it.
     *
     * @return list<IssueChange>
     */
    public function record(RequestScope $scope, ?int $now = null): array
    {
        return $this->classify($scope, $now ?? time(), persist: true);
    }

    /**
     * The same answer without remembering it — what the digest reads.
     *
     * @return list<IssueChange>
     */
    public function preview(RequestScope $scope, ?int $now = null): array
    {
        return $this->classify($scope, $now ?? time(), persist: false);
    }

    /**
     * @return list<IssueChange>
     */
    private function classify(RequestScope $scope, int $now, bool $persist): array
    {
        $reading = $this->monitor->read($scope, $now);

        // A failed read is not a healthy schedule: change nothing.
        if ($reading === null || $reading->isEmpty()) {
            return [];
        }

        $problems = [];

        foreach ($reading->problems as $problem) {
            $problems[$problem->fingerprint()] = $problem;
        }

        $known = $problems === []
            ? collect()
            : Issue::query()->whereIn('fingerprint', array_keys($problems))->get()->keyBy('fingerprint');

        $changes = [];
        $at = Carbon::createFromTimestamp($now);

        foreach ($problems as $fingerprint => $problem) {
            $existing = $known->get($fingerprint);

            $changes[] = $existing instanceof Issue
                ? $this->existing($existing, $problem, $at, $persist, $scope, $now)
                : $this->fresh((string) $fingerprint, $problem, $at, $persist);
        }

        foreach ($this->cleared($reading, array_keys($problems)) as $issue) {
            if ($persist) {
                $this->actions->resolve($issue, as: Attribution::scan('Cleared: '.$this->clearedBecause($issue)));
            }

            $changes[] = new IssueChange($issue, ChangeKind::Recovered);
        }

        return $changes;
    }

    private function fresh(string $fingerprint, ScheduleProblem $problem, Carbon $at, bool $persist): IssueChange
    {
        $issue = new Issue;
        $issue->forceFill([
            'fingerprint' => $fingerprint,
            'kind' => $problem->kind,
            'status' => IssueStatus::Open,
            'type' => $problem->kind->label(),
            'message' => $problem->summary,
            'service' => $problem->service !== '' ? $problem->service : null,
            'environment' => $problem->environment !== '' ? $problem->environment : null,
            'subject' => $problem->task,
            'first_recorded_at' => $at,
            'last_seen_at' => $at,
        ]);

        if ($persist) {
            $issue->save();
            $this->actions->log($issue, IssueAction::Opened, Attribution::scan());
        }

        return new IssueChange($issue, ChangeKind::New, self::count($problem));
    }

    private function existing(Issue $issue, ScheduleProblem $problem, Carbon $at, bool $persist, RequestScope $scope, int $now): IssueChange
    {
        $back = $this->isBack($issue, $problem, $scope, $now);

        $issue->forceFill([
            'message' => $problem->summary,
            'last_seen_at' => $at,
            'status' => $back ? IssueStatus::Open : $issue->status,
            'resolved_at' => $back ? null : $issue->resolved_at,
            'resolved_by' => $back ? null : $issue->resolved_by,
        ]);

        if ($persist) {
            $issue->save();

            if ($back) {
                $this->actions->log($issue, IssueAction::Regressed, Attribution::scan());
            }
        }

        return new IssueChange($issue, $back ? ChangeKind::Regression : ChangeKind::Recurring, self::count($problem));
    }

    private function isBack(Issue $issue, ScheduleProblem $problem, RequestScope $scope, int $now): bool
    {
        if ($issue->status !== IssueStatus::Resolved) {
            return false;
        }

        if ($issue->resolved_by === Attribution::SCAN_ACTOR) {
            return true;
        }

        $resolvedAt = $issue->resolved_at;

        if ($resolvedAt === null || $problem->since > $resolvedAt->getTimestamp()) {
            return true;
        }

        // A failure streak that began before the resolve but kept failing
        // AFTER it: the person called it fixed and it was not. Unlike a miss
        // or an overrun, a failure is an event with a time, so this is
        // decidable — one read, and a failed read decides nothing.
        if ($problem->kind === IssueKind::TaskFailing && $problem->task !== null) {
            $failures = $this->monitor->failuresSince($scope, $problem->service, $problem->environment, $problem->task, $resolvedAt->getTimestamp(), $now);

            return $failures !== null && $failures > 0;
        }

        return false;
    }

    /**
     * Open scheduled-task issues this reading is entitled to call healthy,
     * and that it did not find a problem for. Snoozed ones are a person's
     * decision and are left alone while the snooze lasts, like ignored ones.
     *
     * @param  list<array-key>  $firing
     * @return list<Issue>
     */
    private function cleared(ScheduleReading $reading, array $firing): array
    {
        $candidates = Issue::query()
            ->whereIn('kind', array_map(static fn (IssueKind $k): string => $k->value, IssueKind::scheduled()))
            // Effectively open: open, or a snooze that has run out.
            ->where(static fn ($query) => $query
                ->where('status', IssueStatus::Open->value)
                ->orWhere(static fn ($q) => $q->where('status', IssueStatus::Snoozed->value)->where('snoozed_until', '<=', Carbon::now())))
            ->when($firing !== [], static fn ($query) => $query->whereNotIn('fingerprint', $firing))
            ->get();

        $cleared = [];

        foreach ($candidates as $issue) {
            if (! $issue instanceof Issue) {
                continue;
            }

            $kind = $issue->kind();
            $service = $issue->service ?? '';
            $environment = $issue->environment ?? '';

            if (! $reading->covers($kind, $service, $environment)) {
                continue;
            }

            // A failure streak ends with a success, not with a quiet window:
            // a nightly task that failed at 02:00 is still failing at 09:00.
            if ($kind === IssueKind::TaskFailing) {
                $succeeded = $reading->lastSucceeded($service, $environment, $issue->subject ?? '');
                $lastSeen = $issue->last_seen_at;

                if ($succeeded === null || ($lastSeen !== null && $succeeded <= $lastSeen->getTimestamp())) {
                    continue;
                }
            }

            $cleared[] = $issue;
        }

        return $cleared;
    }

    private function clearedBecause(Issue $issue): string
    {
        return match ($issue->kind()) {
            IssueKind::SchedulerStopped => 'schedule:run is starting again.',
            IssueKind::TaskMissed => 'the task ran again (or left the schedule).',
            IssueKind::TaskOverrunning => 'the run finished.',
            IssueKind::TaskFailing => 'the task succeeded.',
            IssueKind::Exception => '',
        };
    }

    /** What a scheduled-task issue counts as "occurrences": failures in a row, else one. */
    private static function count(ScheduleProblem $problem): int
    {
        return $problem->kind === IssueKind::TaskFailing ? max(1, (int) $problem->magnitude) : 1;
    }
}
