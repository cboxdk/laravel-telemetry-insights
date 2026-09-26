<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Issues;

use Cbox\TelemetryInsights\Models\Issue;
use Illuminate\Support\Carbon;

/**
 * The decisions a team makes about an error group.
 *
 * Without these the ledger is write-only and regression detection is
 * unreachable: nothing can ever be resolved, so nothing can ever come back.
 * They are deliberately a service rather than model methods, so the artisan
 * command, a host's own UI and a webhook all go through one implementation.
 */
class IssueActions
{
    /**
     * Believed fixed. Record the release it shipped in and a later
     * occurrence can say which fix it escaped.
     */
    public function resolve(Issue $issue, ?string $by = null, ?string $release = null): Issue
    {
        return $this->apply($issue, [
            'status' => IssueStatus::Resolved,
            'resolved_at' => Carbon::now(),
            'resolved_by' => $by,
            'resolved_in_release' => $release,
            'snoozed_until' => null,
        ]);
    }

    /** Known, deliberately not worth acting on. Never announced again. */
    public function ignore(Issue $issue): Issue
    {
        return $this->apply($issue, [
            'status' => IssueStatus::Ignored,
            'resolved_at' => null,
            'snoozed_until' => null,
        ]);
    }

    /**
     * Quiet for a while. A lapsed snooze reads as open again without a
     * sweep, so there is nothing to schedule.
     */
    public function snooze(Issue $issue, Carbon $until): Issue
    {
        return $this->apply($issue, [
            'status' => IssueStatus::Snoozed,
            'snoozed_until' => $until,
            'resolved_at' => null,
        ]);
    }

    /** Back on the working list, whatever it was before. */
    public function reopen(Issue $issue): Issue
    {
        return $this->apply($issue, [
            'status' => IssueStatus::Open,
            'resolved_at' => null,
            'snoozed_until' => null,
        ]);
    }

    public function assign(Issue $issue, ?string $to): Issue
    {
        return $this->apply($issue, ['assignee' => $to]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function apply(Issue $issue, array $attributes): Issue
    {
        $issue->forceFill($attributes)->save();

        return $issue;
    }
}
