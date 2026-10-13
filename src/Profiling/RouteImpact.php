<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Profiling;

/** One route's share of the work, as the rotation ranks it. */
final readonly class RouteImpact
{
    public function __construct(
        public ?string $service,
        /** The `http_route` label as the metric carries it. */
        public string $route,
        /** Bigger is worse, in the measure's own unit. */
        public float $impact,
        /** "1,234s total" or "0.8s p95 × 12 req/s" — for a person. */
        public string $label,
    ) {}

    /**
     * The pattern a `route` directive matches: the route's URI template,
     * which Laravel keeps without its leading slash.
     */
    public function pattern(): string
    {
        return $this->route === '/' ? '/' : ltrim($this->route, '/');
    }
}
