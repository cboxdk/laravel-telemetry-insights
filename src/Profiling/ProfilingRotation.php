<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Profiling;

use Cbox\TelemetryInsights\Models\ProfilingRun;
use Cbox\TelemetryInsights\Support\Setting;
use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Http\Api\RequestScope;
use Cbox\TelemetryUi\Queries\Results\Sample;
use Illuminate\Support\Carbon;

/**
 * Profiles the routes that cost the most, a few at a time, in turn.
 *
 * Each pass ranks routes by impact from the request-duration histogram —
 * total time spent in them, or p95 × request rate — skips the ones
 * profiled within the cooldown, and asks the monitored app for a `route`
 * directive with a CPU profile on the top few, for a limited number of
 * minutes. The ledger of what was profiled is what makes it rotate.
 *
 * The cost is real and bounded: for those minutes every request on those
 * routes is kept (sampled at 100%) and profiled. That is the point — a
 * profile of the slow path, not of whatever the sample rate happened to
 * keep — and it is why this is off by default.
 */
final readonly class ProfilingRotation
{
    private const PREFIX = 'telemetry-insights.profiling.rotation.';

    private const HISTOGRAM = 'http_server_request_duration_seconds';

    private const SERVICE = 'service_name';

    private const ROUTE = 'http_route';

    public function __construct(
        private ConnectionManager $connections,
        private Directives $directives,
        private Setting $settings,
    ) {}

    public function enabled(): bool
    {
        return $this->settings->bool(self::PREFIX.'enabled', false);
    }

    /**
     * Routes ranked by impact, worst first, minus the ones still in their
     * cooldown. Empty when the metrics store does not answer.
     *
     * @return list<RouteImpact>
     */
    public function candidates(RequestScope $scope): array
    {
        try {
            $ranked = $this->settings->string(self::PREFIX.'impact', 'total_time') === 'p95_rate'
                ? $this->byP95Rate($scope)
                : $this->byTotalTime($scope);
        } catch (SourceException) {
            return [];
        }

        $recent = $this->recentlyProfiled();
        $reachable = [];

        // Dropped before the top N is taken: a service nothing can reach
        // would otherwise take a slot every pass and fail every time.
        $fresh = array_values(array_filter(
            $ranked,
            function (RouteImpact $r) use ($recent, &$reachable): bool {
                $service = $r->service ?? '';

                return ! isset($recent[$service."\0".$r->route])
                    && ($reachable[$service] ??= $this->directives->reachable($r->service));
            },
        ));

        usort($fresh, static fn (RouteImpact $a, RouteImpact $b): int => $b->impact <=> $a->impact);

        return $fresh;
    }

    /**
     * One pass: profile the top N fresh routes. A dry run ranks and stops.
     *
     * @return list<ProfilingRun>
     */
    public function rotate(RequestScope $scope): array
    {
        $count = max(1, $this->settings->int(self::PREFIX.'routes', 3));
        $minutes = max(1, $this->settings->int(self::PREFIX.'minutes', 15));
        $profile = $this->settings->bool(self::PREFIX.'profile', true);

        $runs = [];
        $rank = 0;

        foreach (array_slice($this->candidates($scope), 0, $count) as $route) {
            $rank++;

            $runs[] = $this->directives->place(new DirectiveRequest(
                match: 'route',
                value: $route->route,
                minutes: $minutes,
                profile: $profile,
                reason: 'insights profiling rotation: #'.$rank.' by impact, '.$route->label,
                createdBy: 'insights',
                service: $route->service,
            ), ProfilingRun::ROTATION, $route->impact);
        }

        return $runs;
    }

    /**
     * Seconds spent in each route over the window — the work a route
     * costs the fleet, whether it is slow or merely busy.
     *
     * @return list<RouteImpact>
     */
    private function byTotalTime(RequestScope $scope): array
    {
        $samples = $this->connections->metrics()->query(
            $scope->metricQuery(self::HISTOGRAM.'_sum')
                ->increase($scope->promDuration())
                ->sumBy(self::SERVICE, self::ROUTE),
        );

        $routes = [];

        foreach ($samples as $sample) {
            $route = $this->route($sample);

            if ($route === null || $sample->value <= 0) {
                continue;
            }

            $routes[] = new RouteImpact($route[0], $route[1], $sample->value, round($sample->value).'s total in '.$scope->period);
        }

        return $routes;
    }

    /**
     * p95 latency × request rate: how much slow-path time a route produces
     * per second. Weights a slow, busy route over a slow, rare one.
     *
     * @return list<RouteImpact>
     */
    private function byP95Rate(RequestScope $scope): array
    {
        $p95 = $this->connections->metrics()->query(
            $scope->metricQuery(self::HISTOGRAM.'_bucket')->quantile(0.95, $scope->promDuration(), self::SERVICE, self::ROUTE),
        );

        $rates = [];

        foreach ($this->connections->metrics()->query(
            $scope->metricQuery(self::HISTOGRAM.'_count')->rate($scope->promDuration())->sumBy(self::SERVICE, self::ROUTE),
        ) as $sample) {
            $route = $this->route($sample);

            if ($route !== null) {
                $rates[($route[0] ?? '')."\0".$route[1]] = $sample->value;
            }
        }

        $routes = [];

        foreach ($p95 as $sample) {
            $route = $this->route($sample);
            $rate = $route === null ? 0.0 : ($rates[($route[0] ?? '')."\0".$route[1]] ?? 0.0);

            if ($route === null || $sample->value <= 0 || $rate <= 0) {
                continue;
            }

            $routes[] = new RouteImpact(
                $route[0],
                $route[1],
                $sample->value * $rate,
                round($sample->value * 1000).'ms p95 × '.round($rate, 2).' req/s',
            );
        }

        return $routes;
    }

    /**
     * @return array{0: string|null, 1: string}|null
     */
    private function route(Sample $sample): ?array
    {
        $route = $sample->labels[self::ROUTE] ?? '';

        if ($route === '' || ! is_finite($sample->value)) {
            return null;
        }

        $service = $sample->labels[self::SERVICE] ?? '';

        return [$service !== '' ? $service : null, $route];
    }

    /**
     * Routes tried within their cooldown, keyed service + route: a placed
     * directive for `cooldown_hours`, a failed one for the shorter
     * `failed_cooldown_hours` — so an app that refuses is retried, but not
     * hammered every pass.
     *
     * @return array<string, true>
     */
    private function recentlyProfiled(): array
    {
        $placed = Carbon::now()->subHours(max(0, $this->settings->int(self::PREFIX.'cooldown_hours', 24)));
        $failed = Carbon::now()->subHours(max(0, $this->settings->int(self::PREFIX.'failed_cooldown_hours', 1)));

        $runs = ProfilingRun::query()
            ->where('match', 'route')
            ->where(static fn ($query) => $query
                ->where(static fn ($q) => $q->where('status', ProfilingRun::PLACED)->where('created_at', '>=', $placed))
                ->orWhere(static fn ($q) => $q->where('status', ProfilingRun::FAILED)->where('created_at', '>=', $failed)))
            ->get(['service', 'value']);

        $recent = [];

        foreach ($runs as $run) {
            if ($run instanceof ProfilingRun) {
                $recent[($run->service ?? '')."\0".$run->value] = true;
            }
        }

        return $recent;
    }
}
