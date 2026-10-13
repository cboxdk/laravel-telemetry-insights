<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Issues;

use Cbox\TelemetryInsights\Models\Issue;
use Cbox\TelemetryInsights\Models\IssueEvent;
use Illuminate\Support\Carbon;

/**
 * The decisions a team makes about an issue.
 *
 * Without these the ledger is write-only and regression detection is
 * unreachable: nothing can ever be resolved, so nothing can ever come back.
 * They are deliberately a service rather than model methods, so the artisan
 * command, a host's own UI, a webhook and an agent all go through one
 * implementation — and all leave the same trail in the issue's history.
 *
 * Every action takes an optional {@see Attribution}: who did it, through
 * which door, and why. Leave it out and the history says "via code".
 */
class IssueActions
{
    public function __construct(private readonly ?EscalationWatch $escalation = null) {}

    /**
     * Quiet until it gets worse. Learns the group's hourly volume over the
     * previous week and reopens it only when it goes past that — see
     * {@see EscalationWatch}. Error groups only: a scheduled-task issue
     * already clears and returns by itself, precisely.
     *
     * @throws \InvalidArgumentException for a scheduled-task issue
     */
    public function archive(Issue $issue, ?Attribution $as = null): Issue
    {
        if ($issue->kind()->isScheduled()) {
            throw new \InvalidArgumentException('Only error groups can be archived until escalating; a scheduled-task issue clears and returns on its own.');
        }

        $watch = $this->escalation ?? app(EscalationWatch::class);
        $limits = $watch->learn($issue);
        $as ??= new Attribution;

        $issue->forceFill([
            'status' => IssueStatus::Archived,
            'archived_at' => Carbon::now(),
            'escalation_spike_limit' => $limits->spike,
            'escalation_burst_limit' => $limits->burst,
            'resolved_at' => null,
            'snoozed_until' => null,
        ])->save();

        $note = ucfirst($limits->describe($watch->sustainedHours())).'.';

        $this->log($issue, IssueAction::Archived, new Attribution(
            $as->source,
            $as->actor,
            $as->comment === null ? $note : $as->comment.' — '.$note,
        ));

        return $issue;
    }

    /**
     * Believed fixed. Record the release it shipped in and a later
     * occurrence can say which fix it escaped.
     */
    public function resolve(Issue $issue, ?string $by = null, ?string $release = null, ?Attribution $as = null): Issue
    {
        $as ??= new Attribution(actor: $by);

        return $this->apply($issue, IssueAction::Resolved, $as, [
            'status' => IssueStatus::Resolved,
            'resolved_at' => Carbon::now(),
            'resolved_by' => $by ?? $as->actor,
            'resolved_in_release' => $release,
            'snoozed_until' => null,
        ]);
    }

    /** Known, deliberately not worth acting on. Never announced again. */
    public function ignore(Issue $issue, ?Attribution $as = null): Issue
    {
        return $this->apply($issue, IssueAction::Ignored, $as, [
            'status' => IssueStatus::Ignored,
            'resolved_at' => null,
            'snoozed_until' => null,
        ]);
    }

    /**
     * Quiet for a while. A lapsed snooze reads as open again without a
     * sweep, so there is nothing to schedule.
     */
    public function snooze(Issue $issue, Carbon $until, ?Attribution $as = null): Issue
    {
        return $this->apply($issue, IssueAction::Snoozed, $as, [
            'status' => IssueStatus::Snoozed,
            'snoozed_until' => $until,
            'resolved_at' => null,
        ]);
    }

    /** Back on the working list, whatever it was before. Clears any archive limits. */
    public function reopen(Issue $issue, ?Attribution $as = null): Issue
    {
        return $this->apply($issue, IssueAction::Reopened, $as, [
            'status' => IssueStatus::Open,
            'resolved_at' => null,
            'snoozed_until' => null,
            'archived_at' => null,
        ]);
    }

    public function assign(Issue $issue, ?string $to, ?Attribution $as = null): Issue
    {
        return $this->apply($issue, IssueAction::Assigned, $as, ['assignee' => $to]);
    }

    /**
     * Add an entry to the history without changing the issue — the scan
     * uses this for "opened" and "came back", which it decides itself.
     */
    public function log(Issue $issue, IssueAction $action, ?Attribution $as = null): IssueEvent
    {
        $as ??= new Attribution;

        $event = new IssueEvent;
        $event->forceFill([
            'issue_id' => $issue->id,
            'action' => $action,
            'actor' => self::clip($as->actor, 255),
            'source' => $as->source,
            'comment' => self::clip($as->comment, 2000),
            'created_at' => Carbon::now(),
        ])->save();

        return $event;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function apply(Issue $issue, IssueAction $action, ?Attribution $as, array $attributes): Issue
    {
        $issue->forceFill($attributes)->save();

        $this->log($issue, $action, $as);

        return $issue;
    }

    private static function clip(?string $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $length);
    }
}
