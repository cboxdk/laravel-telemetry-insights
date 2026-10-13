<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Profiling;

/** One route's share of the work, as the rotation ranks it. */
final readonly class RouteImpact
{
    public function __construct(
        public ?string $service,
        /**
         * The `http_route` label exactly as the metric carries it. A `route`
         * directive matches it as-is (as well as the route name, the URI
         * without its slash and the logical route), so it is sent unchanged.
         */
        public string $route,
        /** Bigger is worse, in the measure's own unit. */
        public float $impact,
        /** "1,234s total" or "0.8s p95 × 12 req/s" — for a person. */
        public string $label,
    ) {}
}
