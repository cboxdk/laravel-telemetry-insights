<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Schedule;

use Cbox\TelemetryInsights\Issues\IssueKind;

/**
 * One thing wrong with the schedule right now: a scheduler that stopped, or
 * one task that missed its slot, overran, or keeps failing.
 *
 * It is a condition, not an occurrence, so its identity is WHERE it is
 * (kind, service, environment, task) and never when: the same task missing
 * the same slot on the next pass is the same problem, and that is what
 * {@see fingerprint()} encodes.
 */
final readonly class ScheduleProblem
{
    /**
     * @param  array<string, string>  $facts
     */
    public function __construct(
        public IssueKind $kind,
        public string $service,
        public string $environment,
        /** The task's name; null for the scheduler itself. */
        public ?string $task,
        /** One sentence, with the numbers in it. */
        public string $summary,
        public array $facts = [],
        /** How bad: seconds behind or over, or failures in a row. */
        public float $magnitude = 0.0,
        /**
         * Unix seconds when this episode began — the task's last run for a
         * miss, the run's start for an overrun, the last success for a
         * failure streak, the scheduler's last start for a stop. A person
         * who resolves an issue is closing an episode; a new one starting
         * after that is news again.
         */
        public float $since = 0.0,
    ) {}

    /** Stable across passes: kind, service, environment and task. Hex. */
    public function fingerprint(): string
    {
        return self::fingerprintFor($this->kind, $this->service, $this->environment, $this->task);
    }

    public static function fingerprintFor(IssueKind $kind, string $service, string $environment, ?string $task): string
    {
        return substr(hash('sha256', implode("\0", ['schedule', $kind->value, $service, $environment, $task ?? ''])), 0, 40);
    }

    public function title(): string
    {
        if ($this->task !== null) {
            return $this->kind->label().': '.$this->task;
        }

        $where = implode(' · ', array_filter([$this->service, $this->environment]));

        return $where === '' ? $this->kind->label() : $this->kind->label().' on '.$where;
    }
}
