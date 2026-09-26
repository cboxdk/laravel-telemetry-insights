<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Console;

use Cbox\TelemetryInsights\Issues\IssueStatus;
use Cbox\TelemetryInsights\Models\Issue;
use Illuminate\Console\Command;

/** The working list, from the terminal. Open first, because that is the job. */
final class IssuesCommand extends Command
{
    public const NAME = 'telemetry-insights:issues';

    protected $signature = self::NAME
        .' {--status= : open, resolved, ignored or snoozed}'
        .' {--limit=25 : how many to show}';

    protected $description = 'List recorded error groups and their status.';

    public function handle(): int
    {
        $status = $this->option('status');
        $status = is_string($status) && $status !== '' ? IssueStatus::tryFrom($status) : null;

        $limit = (int) $this->option('limit');

        $issues = Issue::query()
            ->when($status !== null, static fn ($query) => $query->where('status', $status?->value))
            ->orderByRaw("case when status = 'open' then 0 else 1 end")
            ->orderByDesc('last_seen_at')
            ->limit($limit > 0 ? $limit : 25)
            ->get();

        if ($issues->isEmpty()) {
            $this->info('Nothing recorded yet. Run telemetry-insights:scan.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($issues as $issue) {
            if (! $issue instanceof Issue) {
                continue;
            }

            $rows[] = [
                $issue->fingerprint,
                $issue->effectiveStatus()->label(),
                $issue->type ?? 'Exception',
                $issue->service ?? '—',
                $issue->last_seen_at?->diffForHumans() ?? '—',
                $issue->assignee ?? '',
            ];
        }

        $this->table(['Fingerprint', 'Status', 'Exception', 'Service', 'Last seen', 'Owner'], $rows);

        return self::SUCCESS;
    }
}
