<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Schedule;

use Cbox\TelemetryInsights\Issues\IssueKind;
use Cbox\TelemetryInsights\Support\Setting;
use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Http\Api\RequestScope;
use Cbox\TelemetryUi\Queries\Results\Sample;

/**
 * Reads the scheduler's gauges and says what is wrong with the schedule.
 *
 * cboxdk/laravel-telemetry publishes, per task, when cron last said it was
 * due, when `schedule:run` last listed it, when it last started (or was
 * skipped), whether a run is in progress, and when it last succeeded — plus
 * when `schedule:run` itself last started. The failures that hurt most are
 * the runs that never happened, and no event fires for those; these gauges
 * are the only way to see them.
 *
 * Every host writes its own series, so each gauge is read as the max across
 * hosts: an `onOneServer()` task is not "missed" by the servers that lost
 * the lock.
 *
 * The thresholds are applied here in PHP rather than in the query, which is
 * what makes per-task overrides possible and keeps the queries to plain
 * selectors any metrics backend can answer.
 */
final class ScheduleMonitor
{
    public const RUN_LAST_SEEN = 'schedule_run_last_seen_seconds';

    public const PREVIOUS_DUE = 'schedule_task_previous_due_seconds';

    public const LISTED = 'schedule_task_listed_seconds';

    public const LAST_SEEN = 'schedule_task_last_seen_seconds';

    public const RUNNING_SINCE = 'schedule_task_running_since_seconds';

    public const LAST_SUCCEEDED = 'schedule_task_last_succeeded_seconds';

    public const FAILED = 'schedule_tasks_failed_total';

    public const BACKGROUND_FAILED = 'schedule_background_tasks_failed_total';

    private const SERVICE = 'service_name';

    private const ENVIRONMENT = 'deployment_environment_name';

    private const TASK = 'task';

    /** How far back a failure streak is counted when the task never succeeded. */
    private const STREAK_LOOKBACK = 7 * 86_400;

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly Setting $settings,
        private readonly TaskThresholds $thresholds,
    ) {}

    public function enabled(): bool
    {
        return $this->settings->bool('telemetry-insights.scheduled_tasks.enabled', true);
    }

    /**
     * What is wrong with the schedule right now.
     *
     * Null when the backend could not be read — which is not the same as a
     * healthy schedule, and callers must not resolve anything on it.
     */
    public function read(RequestScope $scope, ?int $now = null): ?ScheduleReading
    {
        if (! $this->enabled()) {
            return new ScheduleReading;
        }

        $now ??= time();

        try {
            return $this->evaluate($scope, $now);
        } catch (SourceException) {
            return null;
        }
    }

    private function evaluate(RequestScope $scope, int $now): ScheduleReading
    {
        $runs = $this->gauge($scope, self::RUN_LAST_SEEN, task: false);

        // No scheduler has ever reported in this scope: nothing to watch,
        // and nothing to skip the other five reads for.
        if ($runs === []) {
            return new ScheduleReading;
        }

        $problems = [];
        $schedulers = [];
        $running = [];
        $stoppedAfter = $this->thresholds->stoppedAfter();

        foreach ($runs as $key => $run) {
            $schedulers[$key] = true;
            $age = $now - $run['value'];

            if ($age <= $stoppedAfter) {
                $running[$key] = true;

                continue;
            }

            $problems[] = new ScheduleProblem(
                kind: IssueKind::SchedulerStopped,
                service: $run['service'],
                environment: $run['environment'],
                task: null,
                summary: 'schedule:run last started '.self::duration($age).' ago, so nothing scheduled has run since.',
                facts: ['Last started' => self::at($run['value']), 'Silent for' => self::duration($age)],
                magnitude: $age,
                since: $run['value'],
            );
        }

        $due = $this->gauge($scope, self::PREVIOUS_DUE);
        $listed = $this->gauge($scope, self::LISTED);
        $seen = $this->gauge($scope, self::LAST_SEEN);
        $since = $this->gauge($scope, self::RUNNING_SINCE);
        $succeeded = $this->gauge($scope, self::LAST_SUCCEEDED);

        $lastSucceeded = [];

        foreach ($succeeded as $key => $sample) {
            $lastSucceeded[$key] = $sample['value'];
        }

        foreach ($due as $key => $task) {
            $problem = $this->missed($task, $listed[$key] ?? null, $seen[$key] ?? null, $running, $now);

            if ($problem !== null) {
                $problems[] = $problem;
            }
        }

        foreach ($since as $task) {
            $problem = $this->overrunning($task, $now);

            if ($problem !== null) {
                $problems[] = $problem;
            }
        }

        foreach ($this->failing($scope, $lastSucceeded, $now) as $problem) {
            $problems[] = $problem;
        }

        return new ScheduleReading($problems, $schedulers, $running, $lastSucceeded);
    }

    /**
     * Due more than the allowance before it last started or was skipped,
     * and still in the schedule. A task that has never run since monitoring
     * began has no `last_seen` and is not judged until it has.
     *
     * @param  array{service: string, environment: string, task: string, value: float}  $due
     * @param  array{service: string, environment: string, task: string, value: float}|null  $listed
     * @param  array{service: string, environment: string, task: string, value: float}|null  $seen
     * @param  array<string, true>  $running
     */
    private function missed(array $due, ?array $listed, ?array $seen, array $running, int $now): ?ScheduleProblem
    {
        $task = $due['task'];

        if ($listed === null || $seen === null || ! $this->thresholds->enabled($task)) {
            return null;
        }

        // Only where the scheduler is alive: a stopped one is its own
        // problem, and its due times are stale.
        if (! isset($running[ScheduleReading::key($due['service'], $due['environment'])])) {
            return null;
        }

        // Dropped from the schedule: no longer due, so it cannot be missed.
        if ($now - $listed['value'] >= $this->thresholds->listedWithin()) {
            return null;
        }

        $behind = $due['value'] - $seen['value'];

        if ($behind <= $this->thresholds->missedAfter($task)) {
            return null;
        }

        return new ScheduleProblem(
            kind: IssueKind::TaskMissed,
            service: $due['service'],
            environment: $due['environment'],
            task: $task,
            summary: $task.' was due at '.self::at($due['value']).' and has not run since '.self::at($seen['value']).' ('.self::duration($behind).' behind).',
            facts: [
                'Due' => self::at($due['value']),
                'Last ran' => self::at($seen['value']),
                'Behind by' => self::duration($behind),
            ],
            magnitude: $behind,
            since: $seen['value'],
        );
    }

    /**
     * A run in progress for longer than the task is allowed. A process that
     * died mid-run never clears `running_since`, so this catches those too.
     *
     * @param  array{service: string, environment: string, task: string, value: float}  $since
     */
    private function overrunning(array $since, int $now): ?ScheduleProblem
    {
        $task = $since['task'];

        if ($since['value'] <= 0 || ! $this->thresholds->enabled($task)) {
            return null;
        }

        $elapsed = $now - $since['value'];
        $allowed = $this->thresholds->overrunAfter($task);

        if ($elapsed <= $allowed) {
            return null;
        }

        return new ScheduleProblem(
            kind: IssueKind::TaskOverrunning,
            service: $since['service'],
            environment: $since['environment'],
            task: $task,
            summary: $task.' started at '.self::at($since['value']).' and has been running for '.self::duration($elapsed)
                .', over its '.self::duration($allowed).' allowance. A worker that died mid-run looks the same.',
            facts: [
                'Started' => self::at($since['value']),
                'Running for' => self::duration($elapsed),
                'Allowed' => self::duration($allowed),
            ],
            magnitude: $elapsed - $allowed,
            since: $since['value'],
        );
    }

    /**
     * Tasks that failed in the window, kept only when they have failed the
     * configured number of times IN A ROW: the failures since the task last
     * succeeded. Counting that costs one read per failing task (two with
     * background tasks), so it only happens for the tasks the window says
     * failed, and at most a bounded number of them.
     *
     * Before laravel-telemetry 3.5 the failure counter only came into being
     * with a task's first failure, and `increase()` cannot see a series go
     * from absent to 1 — so the very first failure of a task was invisible.
     * 3.5 publishes the counter at zero for every listed task.
     *
     * @param  array<string, float>  $lastSucceeded
     * @return list<ScheduleProblem>
     */
    private function failing(RequestScope $scope, array $lastSucceeded, int $now): array
    {
        $failed = $this->failures($scope, $scope->promDuration());

        $limit = max(1, $this->settings->int('telemetry-insights.scheduled_tasks.max_failure_probes', 20));
        $problems = [];

        foreach ($failed as $key => $row) {
            if ($row['value'] < 0.5 || ! $this->thresholds->enabled($row['task'])) {
                continue;
            }

            if ($limit-- <= 0) {
                break;
            }

            $succeeded = $lastSucceeded[$key] ?? 0.0;
            $streak = $this->streak($scope, $row, $succeeded, $now);
            $threshold = $this->thresholds->failureThreshold($row['task']);

            if ($streak < $threshold) {
                continue;
            }

            $problems[] = new ScheduleProblem(
                kind: IssueKind::TaskFailing,
                service: $row['service'],
                environment: $row['environment'],
                task: $row['task'],
                summary: $row['task'].' has failed '.$streak.' time'.($streak === 1 ? '' : 's').' in a row'
                    .($succeeded > 0 ? ' since it last succeeded at '.self::at($succeeded) : ' and has not succeeded since monitoring began').'.',
                facts: array_filter([
                    'Failures in a row' => (string) $streak,
                    'Last succeeded' => $succeeded > 0 ? self::at($succeeded) : '',
                    'Threshold' => $threshold > 1 ? (string) $threshold : '',
                ], static fn (string $v): bool => $v !== ''),
                magnitude: (float) $streak,
                since: $succeeded,
            );
        }

        return $problems;
    }

    /**
     * Failures since the last success — the streak. The since-success read
     * is the answer whenever it answers, including "none": a task that
     * failed on Monday and succeeded on Tuesday is not failing, whatever a
     * week-long window says. The window's count stands in only when that
     * read fails outright.
     *
     * @param  array{service: string, environment: string, task: string, value: float}  $row
     */
    private function streak(RequestScope $scope, array $row, float $succeeded, int $now): int
    {
        $since = $succeeded > 0 ? (int) $succeeded : $now - self::STREAK_LOOKBACK;
        $count = $this->failuresSince($scope, $row['service'], $row['environment'], $row['task'], $since, $now);

        return $count ?? (int) round($row['value']);
    }

    /**
     * How many times a task failed (foreground and background runs) since
     * a moment, up to a week back. Null when the store did not answer.
     */
    public function failuresSince(RequestScope $scope, string $service, string $environment, string $task, int $since, ?int $now = null): ?int
    {
        $now ??= time();
        $seconds = min(self::STREAK_LOOKBACK, max(60, $now - $since));

        $matchers = implode(',', [
            self::SERVICE.'="'.self::escape($service).'"',
            self::ENVIRONMENT.'="'.self::escape($environment).'"',
            self::TASK.'="'.self::escape($task).'"',
        ]);

        try {
            $total = 0.0;

            foreach ([self::FAILED, self::BACKGROUND_FAILED] as $metric) {
                foreach ($this->connections->metrics()->query(
                    $scope->metricQuery($metric, $matchers)->increase($seconds.'s')->sumBy(),
                ) as $sample) {
                    if (is_finite($sample->value)) {
                        $total += $sample->value;
                    }
                }
            }
        } catch (SourceException) {
            return null;
        }

        return (int) round($total);
    }

    /**
     * Failures per task over a range: foreground runs plus background ones
     * (whose counter also carries the exit code, summed away here).
     *
     * @return array<string, array{service: string, environment: string, task: string, value: float}>
     */
    private function failures(RequestScope $scope, string $range): array
    {
        $rows = [];

        foreach ([self::FAILED, self::BACKGROUND_FAILED] as $metric) {
            $samples = $this->connections->metrics()->query(
                $scope->metricQuery($metric)->increase($range)->sumBy(self::SERVICE, self::ENVIRONMENT, self::TASK),
            );

            foreach ($samples as $sample) {
                $row = $this->row($sample);

                if ($row === null) {
                    continue;
                }

                $key = ScheduleReading::key($row['service'], $row['environment'], $row['task']);
                $rows[$key] = isset($rows[$key]) ? ['value' => $rows[$key]['value'] + $row['value']] + $row : $row;
            }
        }

        return $rows;
    }

    /**
     * One gauge, max across hosts, keyed by service/environment(/task).
     *
     * @return array<string, array{service: string, environment: string, task: string, value: float}>
     */
    private function gauge(RequestScope $scope, string $metric, bool $task = true): array
    {
        $by = $task ? [self::SERVICE, self::ENVIRONMENT, self::TASK] : [self::SERVICE, self::ENVIRONMENT];

        $samples = $this->connections->metrics()->query($scope->metricQuery($metric)->maxBy(...$by));

        $rows = [];

        foreach ($samples as $sample) {
            $row = $this->row($sample, $task);

            if ($row === null) {
                continue;
            }

            $rows[ScheduleReading::key($row['service'], $row['environment'], $task ? $row['task'] : null)] = $row;
        }

        return $rows;
    }

    /**
     * @return array{service: string, environment: string, task: string, value: float}|null
     */
    private function row(Sample $sample, bool $task = true): ?array
    {
        $name = $sample->labels[self::TASK] ?? '';

        if (! is_finite($sample->value) || ($task && $name === '')) {
            return null;
        }

        return [
            'service' => $sample->labels[self::SERVICE] ?? '',
            'environment' => $sample->labels[self::ENVIRONMENT] ?? '',
            'task' => $name,
            'value' => $sample->value,
        ];
    }

    private static function escape(string $value): string
    {
        return addcslashes($value, '"\\');
    }

    private static function at(float $timestamp): string
    {
        return gmdate('Y-m-d H:i', (int) $timestamp).' UTC';
    }

    /** "52m", "3h 10m", "2d 4h" — a person reads this, not a parser. */
    public static function duration(float $seconds): string
    {
        $seconds = max(0, (int) round($seconds));

        if ($seconds < 60) {
            return $seconds.'s';
        }

        $minutes = intdiv($seconds, 60);

        if ($minutes < 60) {
            return $minutes.'m';
        }

        $hours = intdiv($minutes, 60);

        if ($hours < 48) {
            $rest = $minutes % 60;

            return $hours.'h'.($rest > 0 ? ' '.$rest.'m' : '');
        }

        $days = intdiv($hours, 24);
        $rest = $hours % 24;

        return $days.'d'.($rest > 0 ? ' '.$rest.'h' : '');
    }
}
