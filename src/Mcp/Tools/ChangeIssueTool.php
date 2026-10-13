<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Mcp\Tools;

use Cbox\TelemetryInsights\Issues\Attribution;
use Cbox\TelemetryInsights\Issues\IssueActions;
use Cbox\TelemetryInsights\Models\Issue;
use Cbox\TelemetryInsights\Support\Setting;
use Cbox\TelemetryUi\Support\ScopeLock;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchema as JsonSchemaFactory;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * Shared base for the tools that change an issue.
 *
 * Authorization is the dashboard's: `manageTelemetryUi`, checked against
 * the transport's user. A transport with no user is only trusted when it is
 * the local stdio server, started from artisan by someone who could run
 * `telemetry-insights:issue` in the same shell anyway; an HTTP request with
 * no user is refused, whatever middleware a host forgot.
 *
 * Every change lands in the issue's history as made via agent, naming the
 * user when there is one and carrying the agent's comment.
 */
abstract class ChangeIssueTool extends LedgerTool
{
    use AuthorizesWrites;

    public function __construct(
        ScopeLock $lock,
        protected readonly IssueActions $actions,
    ) {
        parent::__construct($lock);
    }

    /** "resolve", "ignore", … — used in the reply and the refusal. */
    abstract protected function verb(): string;

    abstract protected function apply(Issue $issue, Attribution $as, Request $request): Issue;

    /** Hidden entirely when the host turned agent writes off. */
    public function shouldRegister(Setting $settings): bool
    {
        return $settings->bool('telemetry-insights.mcp.writes', true);
    }

    /**
     * @return array<string, JsonSchemaFactory>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'fingerprint' => JsonSchemaFactory::string()->description('The issue fingerprint, or a unique prefix of it')->required(),
            'comment' => JsonSchemaFactory::string()->description('Why — recorded in the issue history with the change'),
        ];
    }

    public function handle(Request $request): Response
    {
        $refusal = $this->refuseUnlessAllowed($request, $this->verb().' issues');

        if ($refusal !== null) {
            return $refusal;
        }

        $issue = $this->find((string) $this->string($request, 'fingerprint'));

        if (! $issue instanceof Issue) {
            return Response::error('No issue matches that fingerprint (or the prefix is ambiguous). Use list_issues to find one.');
        }

        $as = Attribution::agent($this->actor($request), $this->string($request, 'comment'));

        try {
            $updated = $this->apply($issue, $as, $request);
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        return Response::text(sprintf(
            '%s `%s` (%s). Status is now %s; recorded in its history as made via agent%s.',
            ucfirst($this->past()),
            $updated->fingerprint,
            $updated->title(),
            strtolower($updated->effectiveStatus()->label()),
            $as->actor !== null ? ' by '.$as->actor : '',
        ));
    }

    protected function past(): string
    {
        return $this->verb().'d';
    }
}
