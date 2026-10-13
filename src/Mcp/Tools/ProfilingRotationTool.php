<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Mcp\Tools;

use Cbox\TelemetryInsights\Models\ProfilingRun;
use Cbox\TelemetryInsights\Profiling\ProfilingRotation;
use Cbox\TelemetryInsights\Profiling\RouteImpact;
use Cbox\TelemetryInsights\Support\ScopeFactory;
use Cbox\TelemetryInsights\Support\Setting;
use Cbox\TelemetryUi\Support\ScopeLock;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchema as JsonSchemaFactory;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * What the profiling rotation (and anyone asking ad hoc) has profiled,
 * when, and whether it is still running — so an agent knows where a fresh
 * CPU profile of a slow route can be found, and what comes next.
 */
#[IsReadOnly]
final class ProfilingRotationTool extends LedgerTool
{
    protected string $name = 'profiling_rotation';

    protected string $description = 'Show the profiling rotation: recent time-limited directives (route, user, …) placed on monitored apps, which are still active, and the rotation settings. Pass next=true to also rank the routes the next rotation would profile (one metrics read). Traces kept by a directive carry telemetry.directive=<id>.';

    public function __construct(
        ScopeLock $lock,
        private readonly ProfilingRotation $rotation,
        private readonly ScopeFactory $scopes,
        private readonly Setting $settings,
    ) {
        parent::__construct($lock);
    }

    /**
     * @return array<string, JsonSchemaFactory>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => JsonSchemaFactory::integer()->description('Recent runs to show (max 100)')->default(20),
            'next' => JsonSchemaFactory::boolean()->description('Also rank the routes the next rotation would pick')->default(false),
        ];
    }

    public function handle(Request $request): Response
    {
        $limit = $this->int($request, 'limit', 20, 1, self::MAX_LIMIT);

        $query = ProfilingRun::query()->orderByDesc('id')->limit($limit);

        if ($this->lock->servicesLocked()) {
            $query->whereIn('service', $this->lock->services());
        }

        // Runs carry no environment, so an environment-locked viewer cannot
        // be shown any of them to be theirs: fail closed.
        if ($this->lock->environmentsLocked()) {
            $query->whereRaw('1 = 0');
        }

        $runs = [];

        foreach ($query->get() as $run) {
            if (! $run instanceof ProfilingRun) {
                continue;
            }

            $runs[] = [
                'at' => self::time($run->created_at),
                'source' => $run->source,
                'service' => $run->service,
                'directive' => $run->target(),
                'minutes' => $run->minutes,
                'profile' => $run->profile ?: null,
                'state' => $run->status === ProfilingRun::PLACED ? ($run->active() ? 'active' : 'expired') : 'failed',
                'directive_id' => $run->directive_id,
                'until' => self::time($run->expires_at),
                'reason' => $run->reason,
                'error' => $run->error,
            ];
        }

        $prefix = 'telemetry-insights.profiling.rotation.';

        $data = [
            'rotation' => [
                'enabled' => $this->rotation->enabled(),
                'routes_per_pass' => $this->settings->int($prefix.'routes', 3),
                'minutes' => $this->settings->int($prefix.'minutes', 15),
                'cooldown_hours' => $this->settings->int($prefix.'cooldown_hours', 24),
                'impact' => $this->settings->string($prefix.'impact', 'total_time'),
            ],
            'runs' => $runs,
            'note' => $runs === [] ? 'Nothing profiled yet.' : null,
        ];

        if (filter_var($request->get('next', false), FILTER_VALIDATE_BOOLEAN) && ! $this->lock->environmentsLocked()) {
            $scope = $this->scopes->make($this->settings->string($prefix.'lookback', '24h'));
            $next = array_filter(
                $this->rotation->candidates($scope),
                fn (RouteImpact $r): bool => ! $this->lock->servicesLocked() || in_array($r->service, $this->lock->services(), true),
            );

            $data['next'] = array_map(
                static fn (RouteImpact $r): array => ['service' => $r->service, 'route' => $r->route, 'impact' => $r->label],
                array_slice(array_values($next), 0, max(1, $this->settings->int($prefix.'routes', 3))),
            );
        }

        return Response::json($this->compact($data));
    }
}
