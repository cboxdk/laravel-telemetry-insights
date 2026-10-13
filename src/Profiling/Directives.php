<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Profiling;

use Cbox\TelemetryInsights\Contracts\PlacesDirectives;
use Cbox\TelemetryInsights\Models\ProfilingRun;
use Cbox\TelemetryInsights\Support\Setting;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Carbon;

/**
 * Places a directive on the right app, and writes down that it did.
 *
 * Which app: a service listed under `telemetry-insights.profiling.targets`
 * goes over the signed endpoint at its URL; otherwise, when
 * cboxdk/laravel-telemetry with directives runs in this app and the
 * service is this app's (or none was named), it goes straight into the
 * local store. Anything else is refused with what to configure.
 */
final readonly class Directives
{
    public function __construct(
        private Container $container,
        private Setting $settings,
    ) {}

    /**
     * @throws DirectiveException when there is no way to reach the service
     */
    public function transport(?string $service): PlacesDirectives
    {
        $targets = $this->settings->array('telemetry-insights.profiling.targets');

        if ($service !== null && is_array($targets[$service] ?? null)) {
            return $this->http($service, $targets[$service]);
        }

        if (LocalDirectives::available() && ($service === null || $service === $this->localService())) {
            return new LocalDirectives($this->container);
        }

        if ($service === null && count($targets) === 1) {
            $name = (string) array_key_first($targets);

            if (is_array($targets[$name])) {
                return $this->http($name, $targets[$name]);
            }
        }

        throw new DirectiveException($service === null
            ? 'No directive transport: laravel-telemetry with directives is not installed here, and no target is configured under telemetry-insights.profiling.targets.'
            : 'No directive transport for service ['.$service.']: add it under telemetry-insights.profiling.targets with its url and secret.');
    }

    /**
     * Ask for it, and record the attempt either way. A failure is recorded
     * rather than thrown, so a rotation that cannot reach one service still
     * tries the next; the run says what went wrong.
     */
    public function place(DirectiveRequest $request, string $source, ?float $impact = null): ProfilingRun
    {
        $run = new ProfilingRun;
        $run->forceFill([
            'source' => $source,
            'service' => $request->service,
            'match' => $request->match,
            'key' => $request->key,
            'value' => mb_substr($request->value, 0, 255),
            'profile' => $request->profile,
            'minutes' => $request->minutes,
            'impact' => $impact,
            'reason' => $request->reason !== null ? mb_substr($request->reason, 0, 255) : null,
            'created_by' => mb_substr($request->createdBy, 0, 255),
            'created_at' => Carbon::now(),
        ]);

        try {
            $placed = $this->transport($request->service)->place($request);

            $run->forceFill([
                'status' => ProfilingRun::PLACED,
                'directive_id' => mb_substr($placed->id, 0, 64),
                'expires_at' => Carbon::createFromTimestamp($placed->expiresAt),
            ]);
        } catch (DirectiveException $e) {
            $run->forceFill(['status' => ProfilingRun::FAILED, 'error' => $e->getMessage()]);
        }

        $run->save();

        return $run;
    }

    private function localService(): string
    {
        $name = $this->container->make('config')->get('telemetry.service.name');

        return is_string($name) ? $name : '';
    }

    /**
     * @param  array<array-key, mixed>  $target
     */
    private function http(string $service, array $target): SignedHttpDirectives
    {
        $url = $target['url'] ?? null;
        $secret = $target['secret'] ?? null;

        if (! is_string($url) || $url === '' || ! is_string($secret) || $secret === '') {
            throw new DirectiveException('The target for ['.$service.'] needs both a url and a secret.');
        }

        $path = $target['path'] ?? null;

        return new SignedHttpDirectives(
            $this->container->make(Http::class),
            $url,
            $secret,
            is_string($path) && $path !== '' ? $path : 'telemetry/directives',
            max(1, $this->settings->int('telemetry-insights.profiling.timeout', 5)),
        );
    }
}
