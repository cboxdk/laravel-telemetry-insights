<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Mcp\Tools;

use Cbox\TelemetryInsights\Issues\IssueVisibility;
use Cbox\TelemetryInsights\Models\ProfilingRun;
use Cbox\TelemetryInsights\Profiling\DirectiveRequest;
use Cbox\TelemetryInsights\Profiling\Directives;
use Cbox\TelemetryInsights\Support\Setting;
use Cbox\TelemetryUi\Support\ScopeLock;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchema as JsonSchemaFactory;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

/**
 * "Watch user 42 for two hours, with a profile": ask a monitored app to
 * keep every trace of one slice of its traffic for a while, optionally
 * CPU-profiled. The directive expires on its own.
 */
#[IsDestructive(false)]
#[IsIdempotent(false)]
final class RequestDirectiveTool extends LedgerTool
{
    use AuthorizesWrites;

    protected string $name = 'request_directive';

    protected string $description = 'Ask a monitored app to keep every trace of one slice of traffic for a while — a route, path, user, context value (e.g. tenant.id) or job — optionally with a CPU profile. Expires on its own (max 24h). Every matching request is fully sampled for the duration, so keep it narrow and short. Kept traces carry telemetry.directive=<id>.';

    private const MAX_MINUTES = 1440;

    public function __construct(
        ScopeLock $lock,
        private readonly Directives $directives,
    ) {
        parent::__construct($lock);
    }

    /** Hidden when the host turned agent writes off. */
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
            'match' => JsonSchemaFactory::string()->enum(DirectiveRequest::MATCHES)->description('What to match')->required(),
            'value' => JsonSchemaFactory::string()->description('The route/path/job pattern (* allowed), the user id, or the context value')->required(),
            'key' => JsonSchemaFactory::string()->description('For match=context: the dimension, e.g. tenant.id'),
            'minutes' => JsonSchemaFactory::integer()->description('How long it lasts (max '.self::MAX_MINUTES.')')->default(60),
            'profile' => JsonSchemaFactory::boolean()->description('Also CPU-profile the matching work')->default(false),
            'reason' => JsonSchemaFactory::string()->description('Why — shown with the directive'),
            'service' => JsonSchemaFactory::string()->description('Which monitored service (default: this app)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $refusal = $this->refuseUnlessAllowed($request, 'request directives');

        if ($refusal !== null) {
            return $refusal;
        }

        $service = $this->string($request, 'service');

        // A viewer locked to some services may only watch those — and must
        // say which, since "this app" cannot be shown to be one of theirs.
        if (! (new IssueVisibility($this->lock))->allowsService($service)) {
            return Response::error('Not authorized to request directives for that service.');
        }

        $actor = $this->actor($request);

        try {
            $directive = new DirectiveRequest(
                match: (string) $this->string($request, 'match'),
                value: (string) $this->string($request, 'value'),
                minutes: $this->int($request, 'minutes', 60, 1, self::MAX_MINUTES),
                profile: filter_var($request->get('profile', false), FILTER_VALIDATE_BOOLEAN),
                reason: $this->string($request, 'reason'),
                createdBy: 'insights (agent'.($actor !== null ? ': '.$actor : '').')',
                key: $this->string($request, 'key'),
                service: $service,
            );
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        $run = $this->directives->place($directive, ProfilingRun::ADHOC);

        if ($run->status !== ProfilingRun::PLACED) {
            return Response::error('Not placed: '.$run->error);
        }

        return Response::text(sprintf(
            'Watching %s%s until %s. Directive `%s`; kept traces carry telemetry.directive=%s.',
            $directive->describe(),
            $service !== null ? ' on '.$service : '',
            self::time($run->expires_at),
            $run->directive_id,
            $run->directive_id,
        ));
    }
}
