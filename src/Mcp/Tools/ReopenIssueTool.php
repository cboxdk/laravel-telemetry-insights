<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Mcp\Tools;

use Cbox\TelemetryInsights\Issues\Attribution;
use Cbox\TelemetryInsights\Models\Issue;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

/** Back on the working list, whatever it was before. */
#[IsDestructive(false)]
#[IsIdempotent]
final class ReopenIssueTool extends ChangeIssueTool
{
    protected string $name = 'reopen_issue';

    protected string $description = 'Reopen an issue that was resolved, ignored or snoozed, putting it back on the working list. Recorded in the issue history as reopened via agent.';

    protected function verb(): string
    {
        return 'reopen';
    }

    protected function past(): string
    {
        return 'reopened';
    }

    protected function apply(Issue $issue, Attribution $as, Request $request): Issue
    {
        return $this->actions->reopen($issue, $as);
    }
}
