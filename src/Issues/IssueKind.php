<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Issues;

/**
 * What an issue is about.
 *
 * An error group is an issue because something happened. A scheduled-task
 * issue is one because something did NOT: the run that never started, the
 * one that never finished, the scheduler that stopped calling. Those are
 * conditions rather than occurrences, so they open while the condition
 * holds and resolve themselves when it clears.
 */
enum IssueKind: string
{
    /** An error group, keyed on the store's own fingerprint. */
    case Exception = 'exception';

    /** `schedule:run` has stopped starting, so nothing scheduled runs. */
    case SchedulerStopped = 'scheduler_stopped';

    /** A task was due and has not started (or been skipped) since. */
    case TaskMissed = 'scheduled_task_missed';

    /** A run started and has not finished in its allowed time. */
    case TaskOverrunning = 'scheduled_task_overrunning';

    /** A task failed this many times in a row. */
    case TaskFailing = 'scheduled_task_failing';

    public function label(): string
    {
        return match ($this) {
            self::Exception => 'Exception',
            self::SchedulerStopped => 'Scheduler stopped',
            self::TaskMissed => 'Scheduled task missed',
            self::TaskOverrunning => 'Scheduled task overrunning',
            self::TaskFailing => 'Scheduled task failing',
        };
    }

    /**
     * Whether this is a condition read off the scheduler's gauges, which
     * clears on its own — as opposed to an error group, which only a person
     * can call fixed.
     */
    public function isScheduled(): bool
    {
        return $this !== self::Exception;
    }

    /**
     * @return list<self>
     */
    public static function scheduled(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $kind): bool => $kind->isScheduled()));
    }
}
