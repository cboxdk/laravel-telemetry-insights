<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Correlate;

/**
 * What an incident is blamed on. Ordered by how directly it explains a burst
 * of unrelated-looking errors: a downstream that is itself failing explains
 * them outright, a deploy explains them circumstantially, a host signal is a
 * symptom that may share their cause.
 */
enum CauseKind: string
{
    /** A shared downstream (database, cache, queue, HTTP peer) that is failing. */
    case Dependency = 'dependency';

    /** A deploy, migration or other annotated change shortly before onset. */
    case Deploy = 'deploy';

    /** A host or runtime signal materially out of its normal band at onset. */
    case Host = 'host';

    public function label(): string
    {
        return match ($this) {
            self::Dependency => 'Failing dependency',
            self::Deploy => 'Recent change',
            self::Host => 'Host pressure',
        };
    }

    /**
     * Higher explains more. Used to pick between competing causes for the
     * same incident.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Dependency => 3,
            self::Deploy => 2,
            self::Host => 1,
        };
    }
}
