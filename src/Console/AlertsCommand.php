<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Console;

use Cbox\TelemetryInsights\Alerts\AlertEvaluator;
use Cbox\TelemetryInsights\Models\AlertRule;
use Illuminate\Console\Command;

/**
 * Evaluates every enabled measurement rule. Event-shaped rules (a new issue,
 * an incident) are fired by the scan instead, when the event happens.
 */
final class AlertsCommand extends Command
{
    public const NAME = 'telemetry-insights:alerts';

    protected $signature = self::NAME.' {--rule=* : Only these rule ids}';

    protected $description = 'Evaluate alert rules and fire the ones that have crossed their threshold.';

    public function handle(AlertEvaluator $evaluator): int
    {
        $ids = array_values(array_filter(
            (array) $this->option('rule'),
            static fn (mixed $v): bool => is_string($v) && $v !== '',
        ));

        $rules = AlertRule::query()
            ->where('enabled', true)
            ->when($ids !== [], static fn ($query) => $query->whereIn('id', $ids))
            ->get();

        $fired = 0;

        foreach ($rules as $rule) {
            if (! $rule instanceof AlertRule || $rule->type->isEvent()) {
                continue;
            }

            if ($evaluator->evaluate($rule) !== null) {
                $fired++;
                $this->warn('Fired: '.$rule->name);
            }
        }

        $this->info($rules->count().' rule(s) evaluated, '.$fired.' fired.');

        return self::SUCCESS;
    }
}
