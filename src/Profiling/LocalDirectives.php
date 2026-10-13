<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Profiling;

use Cbox\Telemetry\TelemetryManager;
use Cbox\TelemetryInsights\Contracts\PlacesDirectives;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Directives for this very app, straight into cboxdk/laravel-telemetry's
 * directive store — no HTTP, no secret.
 *
 * Directives arrived in laravel-telemetry after the version this package
 * requires, so everything here is looked up at runtime and {@see available()}
 * says whether the installed version has them.
 */
final readonly class LocalDirectives implements PlacesDirectives
{
    public const DIRECTIVE = 'Cbox\\Telemetry\\Directives\\Directive';

    public function __construct(private Container $container) {}

    public static function available(): bool
    {
        return class_exists(self::DIRECTIVE)
            && class_exists(TelemetryManager::class)
            && method_exists(TelemetryManager::class, 'directives');
    }

    public function place(DirectiveRequest $request): PlacedDirective
    {
        if (! self::available()) {
            throw new DirectiveException('The installed cboxdk/laravel-telemetry has no directives (directives need 3.5 or later).');
        }

        try {
            $make = [self::DIRECTIVE, 'fromArray'];
            $directives = [$this->container->make(TelemetryManager::class), 'directives'];

            if (! is_callable($make) || ! is_callable($directives)) {
                throw new DirectiveException('The installed cboxdk/laravel-telemetry has no directives.');
            }

            $directive = $make([
                'id' => null,
                ...$request->payload(),
                'expires_at' => time() + $request->minutes * 60,
            ]);

            $store = $directives();
            $add = is_object($store) ? [$store, 'add'] : null;
            $placed = is_callable($add) ? $add($directive) : null;
            $data = is_object($placed) && method_exists($placed, 'toArray') ? $placed->toArray() : null;
        } catch (DirectiveException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new DirectiveException('The directive store refused it: '.$e->getMessage(), previous: $e);
        }

        if (! is_array($data) || ! is_string($data['id'] ?? null)) {
            throw new DirectiveException('The directive store returned nothing usable.');
        }

        return new PlacedDirective($data['id'], is_int($data['expires_at'] ?? null) ? $data['expires_at'] : time() + $request->minutes * 60);
    }
}
