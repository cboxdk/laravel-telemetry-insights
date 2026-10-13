<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Issues;

/** One entry in an issue's history. */
enum IssueAction: string
{
    case Opened = 'opened';
    case Regressed = 'regressed';
    case Resolved = 'resolved';
    case Ignored = 'ignored';
    case Snoozed = 'snoozed';
    case Reopened = 'reopened';
    case Assigned = 'assigned';
    case Archived = 'archived';
    case Escalated = 'escalated';

    public function label(): string
    {
        return match ($this) {
            self::Opened => 'Opened',
            self::Regressed => 'Came back',
            self::Resolved => 'Resolved',
            self::Ignored => 'Ignored',
            self::Snoozed => 'Snoozed',
            self::Reopened => 'Reopened',
            self::Assigned => 'Assigned',
            self::Archived => 'Archived until escalating',
            self::Escalated => 'Escalated',
        };
    }
}
