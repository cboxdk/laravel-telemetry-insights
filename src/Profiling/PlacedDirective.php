<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Profiling;

/** A directive the monitored app accepted: its id, and when it lapses. */
final readonly class PlacedDirective
{
    public function __construct(
        public string $id,
        public int $expiresAt,
    ) {}
}
