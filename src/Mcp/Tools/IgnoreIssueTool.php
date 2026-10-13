<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Mcp\Tools;

use Cbox\TelemetryInsights\Issues\Attribution;
use Cbox\TelemetryInsights\Models\Issue;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

/** Known and deliberately not worth acting on: never announced again. */
#[IsDestructive(false)]
#[IsIdempotent]
final class IgnoreIssueTool extends ChangeIssueTool
{
    protected string $name = 'ignore_issue';

    protected string $description = 'Ignore an issue: known, deliberately not worth acting on, never announced again (reopen_issue undoes it). Give a comment saying why. Recorded in the issue history as ignored via agent.';

    protected function verb(): string
    {
        return 'ignore';
    }

    protected function apply(Issue $issue, Attribution $as, Request $request): Issue
    {
        return $this->actions->ignore($issue, $as);
    }
}
