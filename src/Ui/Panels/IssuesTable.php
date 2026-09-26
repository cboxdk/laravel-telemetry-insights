<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Ui\Panels;

use Cbox\TelemetryInsights\Models\Issue;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;

/**
 * The issue ledger: what this install has seen, and what the team decided
 * about it. Open first, because that is the working list.
 */
class IssuesTable extends Panel
{
    public static function title(): string
    {
        return 'Issues';
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
        $issues = Issue::query()
            ->orderByRaw("case when status = 'open' then 0 else 1 end")
            ->orderByDesc('last_seen_at')
            ->limit(100)
            ->get();

        $rows = [];

        foreach ($issues as $issue) {
            if (! $issue instanceof Issue) {
                continue;
            }

            $status = $issue->effectiveStatus();

            $rows[] = [
                'type' => Ui::cell($issue->type ?? 'Exception', ['sub' => $issue->message]),
                'service' => Ui::cell($issue->service ?? '—'),
                'last' => Ui::cell($issue->last_seen_at?->format('d/m H:i') ?? '—'),
                'status' => Ui::cell($status->label(), [
                    'tone' => $status->notifiable() ? 'danger' : 'dim',
                ]),
                'assignee' => Ui::cell($issue->assignee ?? '—'),
            ];
        }

        return Ui::table(
            self::title(),
            [
                Ui::col('type', 'Exception'),
                Ui::col('service', 'Service'),
                Ui::col('last', 'Last seen'),
                Ui::col('status', 'Status'),
                Ui::col('assignee', 'Assignee'),
            ],
            $rows,
            ['empty' => 'Nothing recorded yet. Run telemetry-insights:scan.'],
        );
    }
}
