<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Ui\Panels;

use Cbox\TelemetryInsights\Models\ProfilingRun;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;
use Cbox\TelemetryUi\Support\ScopeLock;

/**
 * What was profiled when: the rotation's picks and ad-hoc directives, and
 * which are still running. Read-only; the rotation and
 * `telemetry-insights:watch` place them.
 */
class ProfilingTable extends Panel
{
    public static function title(): string
    {
        return 'Profiling';
    }

    public static function span(): int
    {
        return 2;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $lock = app(ScopeLock::class);

        $runs = ProfilingRun::query()
            ->when($lock->servicesLocked(), static fn ($query) => $query->whereIn('service', $lock->services()))
            // Runs carry no environment: an environment-locked viewer sees none.
            ->when($lock->environmentsLocked(), static fn ($query) => $query->whereRaw('1 = 0'))
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $rows = [];

        foreach ($runs as $run) {
            if (! $run instanceof ProfilingRun) {
                continue;
            }

            $state = $run->status === ProfilingRun::PLACED ? ($run->active() ? 'Active' : 'Expired') : 'Failed';

            $rows[] = [
                'directive' => Ui::cell($run->target(), ['sub' => $run->reason ?? '']),
                'service' => Ui::cell($run->service ?? '—'),
                'source' => Ui::cell($run->source === ProfilingRun::ROTATION ? 'Rotation' : 'Ad hoc'),
                'when' => Ui::cell($run->created_at?->format('d/m H:i') ?? '—', ['sub' => $run->minutes.'m'.($run->profile ? ' · profiled' : '')]),
                'state' => Ui::cell($state, [
                    'tone' => $state === 'Active' ? 'ok' : ($state === 'Failed' ? 'danger' : 'dim'),
                    'sub' => $run->error ?? ($run->directive_id ?? ''),
                ]),
            ];
        }

        return Ui::table(
            self::title(),
            [
                Ui::col('directive', 'Directive'),
                Ui::col('service', 'Service'),
                Ui::col('source', 'Source'),
                Ui::col('when', 'Placed'),
                Ui::col('state', 'State'),
            ],
            $rows,
            ['empty' => 'Nothing profiled yet. Enable the rotation, or run telemetry-insights:watch.'],
        );
    }
}
