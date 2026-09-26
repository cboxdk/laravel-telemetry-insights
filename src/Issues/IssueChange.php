<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Issues;

use Cbox\TelemetryInsights\Models\Issue;

final readonly class IssueChange
{
    public function __construct(
        public Issue $issue,
        public ChangeKind $kind,
        /** Occurrences seen for this fingerprint in the window just read. */
        public int $count = 0,
    ) {}
}
