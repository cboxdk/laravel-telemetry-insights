<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Console;

use Cbox\TelemetryInsights\Issues\ActionSource;
use Cbox\TelemetryInsights\Issues\Attribution;
use Cbox\TelemetryInsights\Issues\IssueActions;
use Cbox\TelemetryInsights\Issues\IssueStatus;
use Cbox\TelemetryInsights\Models\Issue;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Act on one error group from the terminal.
 *
 * Resolving is what makes regression detection mean anything: until a group
 * has been called fixed, it can never come back.
 */
final class IssueCommand extends Command
{
    public const NAME = 'telemetry-insights:issue';

    protected $signature = self::NAME
        .' {action : resolve, reopen, ignore, snooze, archive or assign}'
        .' {fingerprint : the error group id, or a unique prefix of it}'
        .' {--release= : the release a fix shipped in (resolve)}'
        .' {--until= : when a snooze lapses, e.g. "3 days" (snooze)}'
        .' {--to= : who owns it (assign)}'
        .' {--by= : who is doing this — recorded in the history}'
        .' {--comment= : why — recorded in the history}';

    protected $description = 'Resolve, reopen, ignore, snooze, archive (until escalating) or assign an issue.';

    public function handle(IssueActions $actions): int
    {
        $fingerprint = $this->stringArgument('fingerprint');
        $issue = $this->find($fingerprint);

        if (! $issue instanceof Issue) {
            $this->error('No issue matches ['.$fingerprint.']. Run telemetry-insights:issues to list them.');

            return self::FAILURE;
        }

        $action = $this->stringArgument('action');

        $as = new Attribution(ActionSource::Console, $this->stringOption('by'), $this->stringOption('comment'));

        $updated = match ($action) {
            'resolve' => $actions->resolve($issue, $as->actor, $this->stringOption('release'), $as),
            'reopen' => $actions->reopen($issue, $as),
            'ignore' => $actions->ignore($issue, $as),
            'snooze' => $actions->snooze($issue, $this->until(), $as),
            'assign' => $actions->assign($issue, $this->stringOption('to'), $as),
            'archive' => $this->archive($actions, $issue, $as),
            default => null,
        };

        if (! $updated instanceof Issue) {
            $this->error('Unknown action ['.$action.']. Use resolve, reopen, ignore, snooze, archive or assign.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%s (%s) is now %s.',
            $updated->title(),
            $updated->fingerprint,
            $updated->effectiveStatus()->label(),
        ));

        if ($updated->effectiveStatus() === IssueStatus::Archived) {
            $this->line('  <fg=gray>It reopens on its own if it escalates: over '.$updated->escalation_spike_limit
                .' an hour for a sustained span, or '.$updated->escalation_burst_limit.' in any one hour.</>');
        }

        if ($updated->effectiveStatus() === IssueStatus::Resolved) {
            $this->line('  <fg=gray>It will be reported as a regression if it fires again.</>');
        }

        return self::SUCCESS;
    }

    private function archive(IssueActions $actions, Issue $issue, Attribution $as): ?Issue
    {
        try {
            return $actions->archive($issue, $as);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return null;
        }
    }

    /** Exact match first, then a unique prefix — fingerprints are long. */
    private function find(string $fingerprint): ?Issue
    {
        $exact = Issue::query()->firstWhere('fingerprint', $fingerprint);

        if ($exact instanceof Issue) {
            return $exact;
        }

        $matches = Issue::query()->where('fingerprint', 'like', $fingerprint.'%')->limit(2)->get();

        if ($matches->count() !== 1) {
            return null;
        }

        $first = $matches->first();

        return $first instanceof Issue ? $first : null;
    }

    private function until(): Carbon
    {
        $until = $this->stringOption('until');

        if ($until === null) {
            return Carbon::now()->addDay();
        }

        try {
            return Carbon::parse('+'.ltrim($until, '+'));
        } catch (\Throwable) {
            return Carbon::now()->addDay();
        }
    }

    private function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        return is_string($value) ? $value : '';
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
