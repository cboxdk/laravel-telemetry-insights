<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Digest;

/**
 * One thing worth a developer's attention, with the common denominator
 * spelled out.
 *
 * The `facts` are what makes a finding actionable rather than merely true:
 * not "requests are slow" but "`GET /checkout`, on web-3, 2.1s p95, 40% of
 * it in one query". They are also what an assistant needs to go looking in
 * the code.
 */
final readonly class Finding
{
    /**
     * @param  array<string, string>  $facts
     */
    public function __construct(
        public FindingKind $kind,
        public string $title,
        public string $detail,
        public array $facts = [],
        /** Breaks ties inside a kind: bigger is worse. */
        public float $magnitude = 0.0,
        public ?string $link = null,
    ) {}

    /** Overall rank: kind first, then how bad this one is. */
    public function score(): float
    {
        return $this->kind->weight() * 1000 + min($this->magnitude, 999);
    }
}
