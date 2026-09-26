<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Issues;

/**
 * Where an error group stands with the team. The occurrences themselves live
 * in the telemetry store and are never copied here — this is only the
 * decision a human made about them.
 */
enum IssueStatus: string
{
    /** Nobody has decided anything yet. */
    case Open = 'open';

    /** Believed fixed. A new occurrence after this reopens it. */
    case Resolved = 'resolved';

    /** Known, deliberately not worth acting on. Never notifies again. */
    case Ignored = 'ignored';

    /** Quiet until `snoozed_until`, then open again. */
    case Snoozed = 'snoozed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Resolved => 'Resolved',
            self::Ignored => 'Ignored',
            self::Snoozed => 'Snoozed',
        };
    }

    /** Whether a fresh occurrence should put this back in front of someone. */
    public function reopensOnRegression(): bool
    {
        return $this === self::Resolved;
    }

    /** Whether this issue may raise a notification right now. */
    public function notifiable(): bool
    {
        return $this === self::Open;
    }
}
