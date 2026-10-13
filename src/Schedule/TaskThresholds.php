<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Schedule;

use Cbox\TelemetryInsights\Support\Setting;
use Illuminate\Support\Str;

/**
 * The limits one task is held to: the defaults under
 * `telemetry-insights.scheduled_tasks`, overridden per task under
 * `scheduled_tasks.tasks`.
 *
 * An override is keyed by the task's name or a `*` pattern of it. An exact
 * name wins over a pattern; among patterns the first listed wins. A nightly
 * export that legitimately runs for three hours gets three hours, and the
 * rest of the schedule keeps the default hour.
 */
final readonly class TaskThresholds
{
    private const PREFIX = 'telemetry-insights.scheduled_tasks.';

    public function __construct(private Setting $settings) {}

    public function enabled(string $task): bool
    {
        $override = $this->override($task)['enabled'] ?? true;

        return ! is_bool($override) || $override;
    }

    public function missedAfter(string $task): int
    {
        return $this->int($task, 'missed_after_seconds', 300);
    }

    public function overrunAfter(string $task): int
    {
        return $this->int($task, 'overrun_after_seconds', 3600);
    }

    /** Failures in a row before a failing task is an issue. */
    public function failureThreshold(string $task): int
    {
        return max(1, $this->int($task, 'failure_threshold', 1));
    }

    public function listedWithin(): int
    {
        return max(60, $this->settings->int(self::PREFIX.'listed_within_seconds', 600));
    }

    public function stoppedAfter(): int
    {
        return max(60, $this->settings->int(self::PREFIX.'scheduler_stopped_after_seconds', 180));
    }

    private function int(string $task, string $key, int $default): int
    {
        $value = $this->override($task)[$key] ?? null;

        if (is_numeric($value)) {
            return max(0, (int) $value);
        }

        return max(0, $this->settings->int(self::PREFIX.$key, $default));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function override(string $task): array
    {
        $tasks = $this->settings->array(self::PREFIX.'tasks');

        if (isset($tasks[$task]) && is_array($tasks[$task])) {
            return $tasks[$task];
        }

        foreach ($tasks as $pattern => $override) {
            if (is_string($pattern) && is_array($override) && str_contains($pattern, '*') && Str::is($pattern, $task)) {
                return $override;
            }
        }

        return [];
    }
}
