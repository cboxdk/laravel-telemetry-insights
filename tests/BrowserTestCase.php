<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Tests;

use Cbox\TelemetryInsights\Testing\InsightsFixture;
use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Testing\FixtureLogs;
use Cbox\TelemetryUi\Testing\FixtureMetrics;
use Cbox\TelemetryUi\Testing\FixtureTraces;

/**
 * The base case for tests that render the insights screens in a browser.
 *
 * Two fixtures, because this package sits on two kinds of data. The
 * dashboard's panels read metrics, traces and logs, which
 * laravel-telemetry-ui's fixture backends answer; the Issues and Incidents
 * screens read THIS package's own tables, which nothing but a seed can
 * fill. A browser pointed at an empty database renders the empty state,
 * which is a fine screen and not the one the documentation is about.
 */
abstract class BrowserTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('telemetry-ui.connections.metrics', ['driver' => 'fixture-metrics']);
        $app['config']->set('telemetry-ui.connections.traces', ['driver' => 'fixture-traces']);
        $app['config']->set('telemetry-ui.connections.logs', ['driver' => 'fixture-logs']);
        $app['config']->set('telemetry-ui.cache.ttl', 0);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $connections = $this->app->make(ConnectionManager::class);
        $connections->extend('fixture-metrics', static fn (array $config): object => new FixtureMetrics);
        $connections->extend('fixture-traces', static fn (array $config): object => new FixtureTraces);
        $connections->extend('fixture-logs', static fn (array $config): object => new FixtureLogs);

        InsightsFixture::seed();
    }
}
