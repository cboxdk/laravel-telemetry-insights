<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Issues;

/** A known issue firing materially harder than it was. */
final readonly class Spike
{
    public function __construct(
        public string $fingerprint,
        public string $type,
        public int $count,
        public int $baseline,
        public float $multiplier,
    ) {}

    public function summary(): string
    {
        return sprintf(
            '%s fired %d times, against %d in the window before — %s× its usual rate.',
            $this->type,
            $this->count,
            $this->baseline,
            $this->multiplier >= 10 ? (string) (int) round($this->multiplier) : (string) round($this->multiplier, 1),
        );
    }
}
