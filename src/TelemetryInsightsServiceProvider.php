<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights;

use Cbox\TelemetryInsights\Console\AlertsCommand;
use Cbox\TelemetryInsights\Console\DigestCommand;
use Cbox\TelemetryInsights\Console\IssueCommand;
use Cbox\TelemetryInsights\Console\IssuesCommand;
use Cbox\TelemetryInsights\Console\ScanCommand;
use Cbox\TelemetryInsights\Contracts\CorrelatesIncidents;
use Cbox\TelemetryInsights\Correlate\IncidentCorrelator;
use Cbox\TelemetryInsights\Ui\InsightsPages;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\ServiceProvider;

/**
 * Boot hygiene, same rule as the dashboard: registrations only. Nothing here
 * opens a connection, reads a backend or touches the database — a package
 * that phones home at boot makes every artisan command slower and every
 * deploy riskier.
 */
class TelemetryInsightsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/telemetry-insights.php', 'telemetry-insights');

        $this->app->bind(CorrelatesIncidents::class, IncidentCorrelator::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/telemetry-insights.php' => config_path('telemetry-insights.php'),
        ], 'telemetry-insights-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'telemetry-insights-migrations');

        if (! $this->enabled()) {
            return;
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ScanCommand::class,
                AlertsCommand::class,
                DigestCommand::class,
                IssuesCommand::class,
                IssueCommand::class,
            ]);
            $this->scheduleJobs();
        }

        InsightsPages::register();
    }

    /**
     * Put the three jobs on the host's scheduler, unless they set the cron
     * to null and would rather schedule them themselves.
     */
    private function scheduleJobs(): void
    {
        $this->app->booted(function (): void {
            /** @var Config $config */
            $config = $this->app->make(Config::class);
            /** @var Schedule $schedule */
            $schedule = $this->app->make(Schedule::class);

            $jobs = [
                'scan' => ScanCommand::NAME,
                'alerts' => AlertsCommand::NAME,
                'digest' => DigestCommand::NAME,
            ];

            foreach ($jobs as $key => $command) {
                $cron = $config->get('telemetry-insights.schedule.'.$key);

                if (! is_string($cron) || $cron === '') {
                    continue;
                }

                $schedule->command($command)->cron($cron)->withoutOverlapping();
            }
        });
    }

    private function enabled(): bool
    {
        /** @var Config $config */
        $config = $this->app->make(Config::class);

        return (bool) $config->get('telemetry-insights.enabled', true);
    }
}
