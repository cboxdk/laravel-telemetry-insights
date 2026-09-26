<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Support;

use Cbox\TelemetryUi\Http\Api\RequestScope;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * The window the scheduled work reads, from config.
 *
 * `RequestScope` is the query layer's scope primitive; it is named for where
 * it usually comes from, but it is a plain value object and works just as
 * well built from a config file in a cron job.
 */
final readonly class ScopeFactory
{
    public function __construct(private Config $config) {}

    public function make(?string $window = null, ?string $service = null, ?string $environment = null): RequestScope
    {
        return new RequestScope(
            period: $window ?? $this->string('window', '15m'),
            service: $service ?? $this->string('service', ''),
            environment: $environment ?? $this->string('environment', ''),
        );
    }

    private function string(string $key, string $default): string
    {
        $value = $this->config->get('telemetry-insights.scope.'.$key, $default);

        return is_string($value) ? $value : $default;
    }
}
