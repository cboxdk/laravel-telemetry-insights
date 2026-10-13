<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Scan;

/** What one pass over a window did. */
final readonly class ScanResult
{
    public function __construct(
        public int $issuesSeen = 0,
        public int $newIssues = 0,
        public int $regressions = 0,
        public int $incidents = 0,
        public int $spikes = 0,
        public int $announced = 0,
        /** Scheduled-task issues currently holding (new, back or ongoing). */
        public int $scheduleProblems = 0,
        /** Of those, the ones that are news this pass. */
        public int $scheduleNews = 0,
        /** Scheduled-task issues resolved because the condition cleared. */
        public int $recovered = 0,
    ) {}

    public function summary(): string
    {
        return sprintf(
            '%d issue(s) seen · %d new · %d regressed · %d spiking · %d incident(s) · %d schedule problem(s), %d recovered · %d announced',
            $this->issuesSeen,
            $this->newIssues,
            $this->regressions,
            $this->spikes,
            $this->incidents,
            $this->scheduleProblems,
            $this->recovered,
            $this->announced,
        );
    }
}
