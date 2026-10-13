<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Console;

use Cbox\TelemetryInsights\Models\ProfilingRun;
use Cbox\TelemetryInsights\Profiling\ProfilingRotation;
use Cbox\TelemetryInsights\Profiling\RouteImpact;
use Cbox\TelemetryInsights\Support\ScopeFactory;
use Cbox\TelemetryInsights\Support\Setting;
use Illuminate\Console\Command;

/**
 * The profiling rotation: profile the costliest routes nobody profiled
 * lately, for a few minutes each. `--dry-run` ranks without asking for
 * anything; `--list` shows what was profiled when.
 */
final class ProfilingCommand extends Command
{
    public const NAME = 'telemetry-insights:profiling';

    protected $signature = self::NAME
        .' {--dry-run : rank the routes, place nothing}'
        .' {--list : show the recent profiling runs}'
        .' {--force : rotate even though the rotation is disabled in config}'
        .' {--service= : limit to one service}';

    protected $description = 'Profile the most expensive routes in turn, through time-limited telemetry directives.';

    public function handle(ProfilingRotation $rotation, ScopeFactory $scopes, Setting $settings): int
    {
        if ((bool) $this->option('list')) {
            return $this->list();
        }

        $service = $this->option('service');
        $scope = $scopes->make(
            $settings->string('telemetry-insights.profiling.rotation.lookback', '24h'),
            is_string($service) && $service !== '' ? $service : null,
        );

        if ((bool) $this->option('dry-run')) {
            $count = max(1, $settings->int('telemetry-insights.profiling.rotation.routes', 3));
            $rows = array_map(
                static fn (RouteImpact $r): array => [$r->service ?? '—', $r->route, $r->label],
                array_slice($rotation->candidates($scope), 0, $count),
            );

            $rows === []
                ? $this->info('Nothing to profile: no route data, or every candidate is in its cooldown.')
                : $this->table(['Service', 'Route', 'Impact'], $rows);

            return self::SUCCESS;
        }

        if (! $rotation->enabled() && ! (bool) $this->option('force')) {
            $this->warn('The profiling rotation is off (telemetry-insights.profiling.rotation.enabled). Use --dry-run to see what it would pick, or --force.');

            return self::SUCCESS;
        }

        $runs = $rotation->rotate($scope);

        if ($runs === []) {
            $this->info('Nothing to profile: no route data, or every candidate is in its cooldown.');

            return self::SUCCESS;
        }

        foreach ($runs as $run) {
            $run->status === ProfilingRun::PLACED
                ? $this->info('Profiling '.$run->value.($run->service !== null ? ' on '.$run->service : '').' for '.$run->minutes.'m (directive '.$run->directive_id.').')
                : $this->error('Could not profile '.$run->value.': '.$run->error);
        }

        return self::SUCCESS;
    }

    private function list(): int
    {
        $runs = ProfilingRun::query()->orderByDesc('id')->limit(25)->get();

        if ($runs->isEmpty()) {
            $this->info('Nothing profiled yet.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($runs as $run) {
            if (! $run instanceof ProfilingRun) {
                continue;
            }

            $rows[] = [
                $run->created_at?->format('Y-m-d H:i') ?? '—',
                $run->source,
                $run->service ?? '—',
                $run->target(),
                $run->minutes.'m'.($run->profile ? ' +profile' : ''),
                $run->status === ProfilingRun::PLACED ? ($run->active() ? 'active' : 'expired') : 'failed: '.$run->error,
            ];
        }

        $this->table(['When', 'Source', 'Service', 'Directive', 'For', 'Status'], $rows);

        return self::SUCCESS;
    }
}
