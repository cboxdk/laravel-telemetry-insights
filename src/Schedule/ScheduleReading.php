<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Schedule;

use Cbox\TelemetryInsights\Issues\IssueKind;

/**
 * What one read of the scheduler's gauges found, and — just as important —
 * what it is entitled to call healthy.
 *
 * A problem that is absent from a reading only means "recovered" where the
 * reading actually looked. A service whose scheduler has stopped publishes
 * no fresh due times, so its tasks cannot be "not missed"; they are simply
 * not evaluated, and their open issues must stay open. {@see covers()} is
 * that distinction.
 */
final readonly class ScheduleReading
{
    /**
     * @param  list<ScheduleProblem>  $problems
     * @param  array<string, true>  $schedulers  service/environment keys with any scheduler data
     * @param  array<string, true>  $running  service/environment keys whose scheduler is running
     * @param  array<string, float>  $lastSucceeded  service/environment/task keys → unix seconds
     */
    public function __construct(
        public array $problems = [],
        private array $schedulers = [],
        private array $running = [],
        private array $lastSucceeded = [],
    ) {}

    public static function key(string $service, string $environment, ?string $task = null): string
    {
        return $task === null ? $service."\0".$environment : $service."\0".$environment."\0".$task;
    }

    /**
     * Whether this reading looked at that kind of problem for that service
     * and environment, so a missing problem there means it has cleared.
     */
    public function covers(IssueKind $kind, string $service, string $environment): bool
    {
        $key = self::key($service, $environment);

        return match ($kind) {
            // Due times only refresh while schedule:run runs, so a miss is
            // only decidable where the scheduler is alive.
            IssueKind::TaskMissed => isset($this->running[$key]),
            IssueKind::SchedulerStopped, IssueKind::TaskOverrunning, IssueKind::TaskFailing => isset($this->schedulers[$key]),
            IssueKind::Exception => false,
        };
    }

    /** When the task last finished successfully, unix seconds; null when unknown. */
    public function lastSucceeded(string $service, string $environment, string $task): ?float
    {
        return $this->lastSucceeded[self::key($service, $environment, $task)] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->problems === [] && $this->schedulers === [];
    }
}
