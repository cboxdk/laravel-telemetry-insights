<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Mcp\Tools;

use Cbox\TelemetryInsights\Issues\Attribution;
use Cbox\TelemetryInsights\Models\Issue;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchema as JsonSchemaFactory;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

/**
 * Mark an issue fixed. An error group that fires again afterwards is
 * reported as a regression; a scheduled-task issue reopens when a new
 * episode of the problem starts.
 */
#[IsDestructive(false)]
#[IsIdempotent]
final class ResolveIssueTool extends ChangeIssueTool
{
    protected string $name = 'resolve_issue';

    protected string $description = 'Mark an issue resolved (believed fixed), with an optional comment and the release the fix shipped in. If it fires again it is reported as a regression. Recorded in the issue history as resolved via agent.';

    protected function verb(): string
    {
        return 'resolve';
    }

    /**
     * @return array<string, JsonSchemaFactory>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...parent::schema($schema),
            'release' => JsonSchemaFactory::string()->description('The release the fix shipped in, e.g. v2.4.1'),
        ];
    }

    protected function apply(Issue $issue, Attribution $as, Request $request): Issue
    {
        return $this->actions->resolve($issue, $as->actor, $this->string($request, 'release'), $as);
    }
}
