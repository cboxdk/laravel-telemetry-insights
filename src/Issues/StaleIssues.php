<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Issues;

use Cbox\TelemetryInsights\Models\Issue;
use Cbox\TelemetryInsights\Support\Setting;
use Cbox\TelemetryUi\Http\Api\RequestScope;
use Illuminate\Support\Carbon;

/**
 * Error groups nobody has seen fire for a while are resolved automatically,
 * so the working list is what is happening rather than everything that
 * ever did.
 *
 * Resolving rather than deleting is the point: the row keeps its history,
 * and a later occurrence comes back as a regression through the usual
 * path — newer than `resolved_at`, so it is news again.
 *
 * Open issues only. Ignored, snoozed and archived ones are a decision
 * someone made; a quiet month does not overrule it.
 */
final readonly class StaleIssues
{
    private const BATCH = 500;

    public function __construct(
        private IssueActions $actions,
        private Setting $settings,
    ) {}

    public function enabled(): bool
    {
        return $this->settings->bool('telemetry-insights.stale.enabled', true) && $this->days() > 0;
    }

    public function days(): int
    {
        return $this->settings->int('telemetry-insights.stale.resolve_after_days', 14);
    }

    /**
     * Resolve the open error groups in scope that have been quiet for the
     * configured days.
     *
     * @return list<Issue>
     */
    public function resolve(RequestScope $scope): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $days = $this->days();

        $stale = Issue::query()
            ->where('kind', IssueKind::Exception->value)
            ->where('status', IssueStatus::Open->value)
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '<', Carbon::now()->subDays($days))
            ->when($scope->service !== '', static fn ($query) => $query->where('service', $scope->service))
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get();

        $resolved = [];

        foreach ($stale as $issue) {
            if (! $issue instanceof Issue) {
                continue;
            }

            $this->actions->resolve($issue, as: Attribution::scan('No occurrence for '.$days.' days.'));
            $resolved[] = $issue;
        }

        return $resolved;
    }
}
