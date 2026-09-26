<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Console;

use Cbox\TelemetryInsights\Scan\Scanner;
use Cbox\TelemetryInsights\Support\ScopeFactory;
use Illuminate\Console\Command;

/**
 * The pass that keeps the ledger current: fold the window's errors in,
 * correlate the bursts, announce what is news.
 */
final class ScanCommand extends Command
{
    public const NAME = 'telemetry-insights:scan';

    protected $signature = self::NAME
        .' {--window= : How far back to read (default: telemetry-insights.scope.window)}'
        .' {--service= : Limit to one service}'
        .' {--env= : Limit to one environment}';

    protected $description = 'Record new and regressed issues, correlate incidents, and announce what changed.';

    public function handle(Scanner $scanner, ScopeFactory $scopes): int
    {
        $scope = $scopes->make(
            $this->stringOption('window'),
            $this->stringOption('service'),
            $this->stringOption('env'),
        );

        $result = $scanner->scan($scope);

        $this->info($result->summary());

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
