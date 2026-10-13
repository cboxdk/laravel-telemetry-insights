<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Mcp\Tools;

use Cbox\TelemetryInsights\Issues\Attribution;
use Cbox\TelemetryInsights\Models\Issue;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

/**
 * Quiet an error group until it gets worse than it was last week. The
 * limits it learns are in the reply and in the issue's history.
 */
#[IsDestructive(false)]
#[IsIdempotent]
final class ArchiveIssueTool extends ChangeIssueTool
{
    protected string $name = 'archive_issue';

    protected string $description = 'Archive an error group until it escalates: it stays quiet unless its hourly volume goes past limits learned from its previous week (sustained above normal, or one hour above its worst). Not for scheduled-task issues. Recorded in the issue history as archived via agent.';

    protected function verb(): string
    {
        return 'archive';
    }

    protected function apply(Issue $issue, Attribution $as, Request $request): Issue
    {
        return $this->actions->archive($issue, $as);
    }
}
