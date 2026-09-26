<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Tests;

use Cbox\TelemetryInsights\TelemetryInsightsServiceProvider;
use Cbox\TelemetryInsights\Testing\InteractsWithInsights;
use Cbox\TelemetryUi\TelemetryUiServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use InteractsWithInsights;
    use RefreshDatabase;

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            TelemetryUiServiceProvider::class,
            TelemetryInsightsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app['config'];

        $config->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $config->set('cache.default', 'array');
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // The read layer points at fakeable hosts; no test reaches a real one.
        $config->set('telemetry-ui.cache.ttl', 0);
        $config->set('telemetry-ui.connections.metrics.url', 'http://prometheus.test:9090');
        $config->set('telemetry-ui.connections.traces.url', 'http://tempo.test:3200');
        $config->set('telemetry-ui.connections.logs.url', 'http://loki.test:3100');
    }
}
