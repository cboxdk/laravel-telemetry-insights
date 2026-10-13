<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Profiling;

use Cbox\Telemetry\Directives\Directive;
use Cbox\Telemetry\TelemetryManager;
use Cbox\TelemetryInsights\Contracts\PlacesDirectives;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Directives for this very app, straight into cboxdk/laravel-telemetry's
 * directive store — no HTTP, no secret.
 *
 * The package is always installed (the dashboard requires it, at a version
 * with directives); what {@see available()} asks is whether it is RUNNING
 * here — its provider registered — rather than merely on the autoloader.
 */
final readonly class LocalDirectives implements PlacesDirectives
{
    public function __construct(private Container $container) {}

    public static function available(?Container $container = null): bool
    {
        return ($container ?? app())->bound(TelemetryManager::class);
    }

    public function place(DirectiveRequest $request): PlacedDirective
    {
        if (! self::available($this->container)) {
            throw new DirectiveException('cboxdk/laravel-telemetry is not running in this app.');
        }

        try {
            $directive = Directive::fromArray([
                'id' => null,
                ...$request->payload(),
                'expires_at' => time() + $request->minutes * 60,
            ]);

            $placed = $this->container->make(TelemetryManager::class)->directives()->add($directive);
        } catch (Throwable $e) {
            throw new DirectiveException('The directive store refused it: '.$e->getMessage(), previous: $e);
        }

        return new PlacedDirective($placed->id, $placed->expiresAt);
    }
}
