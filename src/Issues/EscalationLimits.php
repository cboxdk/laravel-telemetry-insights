<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Issues;

/**
 * What an archived error group may do, per hour, before it is news again —
 * learned from its own previous week.
 *
 * Two limits, because two different things look like "more errors":
 *
 * - the **spike** limit sits above a normal hour (the 95th percentile hour
 *   times a factor). Over it for several hours running is a real rise.
 * - the **burst** limit sits above the worst hour of the week (times a
 *   factor). A nightly job that throws sixty times at 02:00 sets a high
 *   peak and a low normal; its next 02:00 is under the burst limit and
 *   lasts one hour, so it does not reopen anything. Beating the burst
 *   limit in any single hour does.
 */
final readonly class EscalationLimits
{
    public function __construct(
        public int $spike,
        public int $burst,
        /** Hours of history the limits were learned from. */
        public int $hours = 0,
        /** Why the limits are the defaults, when they are. */
        public ?string $note = null,
    ) {}

    public function describe(int $sustainedHours): string
    {
        return sprintf(
            'escalates above %d an hour for %d hours running, or %d in any one hour',
            $this->spike,
            $sustainedHours,
            $this->burst,
        ).($this->note !== null ? ' ('.$this->note.')' : ' (learned from '.$this->hours.' hours)');
    }
}
