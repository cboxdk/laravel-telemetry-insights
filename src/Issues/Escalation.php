<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Issues;

/** An archived error group that went past its limits, and by how much. */
final readonly class Escalation
{
    /**
     * @param  list<int>  $hourly  occurrences per hour, newest first
     */
    public function __construct(
        public array $hourly,
        public int $limit,
        public string $reason,
    ) {}

    public function lastHour(): int
    {
        return $this->hourly[0] ?? 0;
    }
}
