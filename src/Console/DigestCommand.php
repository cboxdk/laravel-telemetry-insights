<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Console;

use Cbox\TelemetryInsights\Digest\DigestBuilder;
use Cbox\TelemetryInsights\Notify\Notification;
use Cbox\TelemetryInsights\Notify\Notifier;
use Cbox\TelemetryInsights\Notify\Severity;
use Cbox\TelemetryInsights\Support\ScopeFactory;
use Illuminate\Console\Command;

/**
 * Builds the window's findings.
 *
 * `--markdown` prints the brief and nothing else, so it pipes: send it to a
 * file, a pull request, or straight into an assistant that has the code open.
 */
final class DigestCommand extends Command
{
    public const NAME = 'telemetry-insights:digest';

    protected $signature = self::NAME
        .' {--window=7d : How far back to summarise}'
        .' {--service= : Limit to one service}'
        .' {--env= : Limit to one environment}'
        .' {--markdown : Print the brief only, for piping into an assistant}'
        .' {--notify : Also send the headline to the notification channels}';

    protected $description = 'Summarise what is worth looking at, as a brief you can hand to an assistant.';

    public function handle(DigestBuilder $builder, ScopeFactory $scopes, Notifier $notifier): int
    {
        $digest = $builder->build($scopes->make(
            $this->stringOption('window'),
            $this->stringOption('service'),
            $this->stringOption('env'),
        ));

        if ((bool) $this->option('markdown')) {
            $this->line($digest->toMarkdown());

            return self::SUCCESS;
        }

        $this->info($digest->headline());
        $this->newLine();

        foreach ($digest->findings as $finding) {
            $this->line('  <fg=yellow>['.$finding->kind->label().']</> '.$finding->title);
            $this->line('    <fg=gray>'.$finding->detail.'</>');
        }

        if ($digest->isEmpty()) {
            $this->line('  Nothing above the configured thresholds.');
        }

        if ((bool) $this->option('notify') && ! $digest->isEmpty()) {
            $notifier->send(new Notification(
                title: 'Telemetry digest',
                body: $digest->headline(),
                severity: Severity::Info,
                facts: ['Findings' => (string) count($digest->findings), 'Scope' => $digest->scope],
                brief: $digest->toMarkdown(),
            ));
        }

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
