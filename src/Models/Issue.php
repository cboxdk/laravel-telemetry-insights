<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Models;

use Cbox\TelemetryInsights\Issues\IssueStatus;
use Cbox\TelemetryInsights\Support\Tables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A team's decision about one error group, keyed on the fingerprint the
 * telemetry store already groups by.
 *
 * This row is an overlay, not a copy: the occurrences, stack traces and
 * counts are read from the store every time. Deleting this row loses the
 * status and nothing else.
 *
 * @property int $id
 * @property string $fingerprint
 * @property IssueStatus $status
 * @property string|null $type
 * @property string|null $message
 * @property string|null $service
 * @property string|null $assignee
 * @property string|null $notes
 * @property Carbon|null $first_recorded_at
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $snoozed_until
 * @property Carbon|null $resolved_at
 * @property string|null $resolved_by
 * @property string|null $resolved_in_release
 * @property Carbon|null $notified_at
 */
class Issue extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return Tables::issues();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => IssueStatus::class,
            'first_recorded_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'snoozed_until' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'notified_at' => 'immutable_datetime',
        ];
    }

    /**
     * The effective status right now: a snooze that has run out is open
     * again, without a job having to sweep the table.
     */
    public function effectiveStatus(): IssueStatus
    {
        if ($this->status === IssueStatus::Snoozed) {
            $until = $this->snoozed_until;

            return $until === null || $until->isPast() ? IssueStatus::Open : IssueStatus::Snoozed;
        }

        return $this->status;
    }

    /**
     * A resolved issue that fired again is a regression — the single most
     * useful thing this table can tell you that the store cannot.
     */
    public function isRegression(Carbon $occurredAt): bool
    {
        if (! $this->status->reopensOnRegression()) {
            return false;
        }

        $resolvedAt = $this->resolved_at;

        return $resolvedAt !== null && $occurredAt->greaterThan($resolvedAt);
    }
}
