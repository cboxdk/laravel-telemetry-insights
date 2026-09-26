<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Ui\Panels;

use Cbox\TelemetryInsights\Models\Incident;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;

/**
 * Correlated incidents, newest first — the bursts, not the individual
 * errors.
 *
 * Reads the package's own table rather than re-running correlation: the
 * dashboard should render in milliseconds, and a page load is not the place
 * to fetch twenty traces.
 */
class IncidentsTable extends Panel
{
    public static function title(): string
    {
        return 'Incidents';
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
        $incidents = Incident::query()
            ->orderByDesc('onset_at')
            ->limit(50)
            ->get();

        $rows = [];

        foreach ($incidents as $incident) {
            if (! $incident instanceof Incident) {
                continue;
            }

            $rows[] = [
                'started' => Ui::cell($incident->onset_at->format('d/m H:i')),
                'title' => Ui::cell($incident->title),
                'cause' => Ui::cell($incident->cause_label ?? '—', [
                    'sub' => $incident->cause_confidence?->value,
                ]),
                'groups' => Ui::cell($incident->group_count),
                'occurrences' => Ui::cell($incident->occurrences),
                'status' => Ui::cell($incident->status->label(), [
                    'tone' => $incident->status->value === 'open' ? 'danger' : 'dim',
                ]),
            ];
        }

        return Ui::table(
            self::title(),
            [
                Ui::col('started', 'Started'),
                Ui::col('title', 'Incident'),
                Ui::col('cause', 'Suspected cause'),
                Ui::col('groups', 'Groups', 'right'),
                Ui::col('occurrences', 'Occurrences', 'right'),
                Ui::col('status', 'Status'),
            ],
            $rows,
            ['empty' => 'No incidents recorded yet. They appear when several error groups start together.'],
        );
    }
}
