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
        public int $announced = 0,
    ) {}

    public function summary(): string
    {
        return sprintf(
            '%d issue(s) seen · %d new · %d regressed · %d incident(s) · %d announced',
            $this->issuesSeen,
            $this->newIssues,
            $this->regressions,
            $this->incidents,
            $this->announced,
        );
    }
}
