<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Ui\Panels;

use Cbox\TelemetryInsights\Issues\IssueStatus;
use Cbox\TelemetryInsights\Models\Issue;
use Cbox\TelemetryUi\Panels\Panel;
use Cbox\TelemetryUi\Panels\Ui;

/**
 * The issue ledger: what this install has seen, and what the team decided
 * about it. Open first, because that is the working list.
 *
 * The status cell carries the actions, so the decision is made where it is
 * read. Whether the viewer may actually make it is the endpoint's call —
 * these are an offer, and `manageTelemetryUi` is checked there.
 *
 * @phpstan-import-type Action from Ui
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
                'type' => Ui::cell($issue->type ?? 'Exception', ['sub' => $issue->message ?? '']),
                'service' => Ui::cell($issue->service ?? '—'),
                'last' => Ui::cell($issue->last_seen_at?->format('d/m H:i') ?? '—'),
                'status' => Ui::cell($status->label(), [
                    'tone' => $status->notifiable() ? 'danger' : 'dim',
                    'actions' => self::actions($issue, $status),
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

    /**
     * What you can do to this issue from here. Resolving is the one that
     * matters: until a group has been called fixed, it can never be
     * reported as a regression.
     *
     * @return list<Action>
     */
    private static function actions(Issue $issue, IssueStatus $status): array
    {
        $endpoint = 'insights/issues/'.$issue->fingerprint;

        $actions = $status === IssueStatus::Open
            ? [
                Ui::action('Resolve', $endpoint, ['action' => 'resolve']),
                Ui::action('Snooze for a day', $endpoint, ['action' => 'snooze', 'until' => '1 day']),
                Ui::action('Ignore', $endpoint, ['action' => 'ignore'],
                    confirm: 'Ignore this for good? It will never be announced again.', tone: 'danger'),
            ]
            : [Ui::action('Reopen', $endpoint, ['action' => 'reopen'])];

        return $actions;
    }
}
