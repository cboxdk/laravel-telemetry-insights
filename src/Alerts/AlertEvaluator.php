<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Alerts;

use Cbox\TelemetryInsights\Models\AlertEvent;
use Cbox\TelemetryInsights\Models\AlertRule;
use Cbox\TelemetryInsights\Notify\Notification;
use Cbox\TelemetryInsights\Notify\Notifier;
use Cbox\TelemetryInsights\Notify\Severity;
use Illuminate\Support\Carbon;

/**
 * Measures a rule and, when it has crossed its line and is not still in its
 * cooldown, records the firing and tells someone.
 *
 * Deliberately three separate decisions — measured, breached, notifiable —
 * because each fails differently: a backend that is down must not read as a
 * breach, and a breach inside a cooldown is still a breach worth recording
 * even though nobody is paged for it.
 */
class AlertEvaluator
{
    public function __construct(
        private readonly RuleMeasurer $measurer,
        private readonly Notifier $notifier,
    ) {}

    /** @return AlertEvent|null the firing, when it fired */
    public function evaluate(AlertRule $rule): ?AlertEvent
    {
        $measurement = $this->measurer->measure($rule);

        $rule->forceFill(['last_evaluated_at' => Carbon::now()])->save();

        if (! $measurement->isAvailable()) {
            return null;
        }

        $value = $measurement->value;

        if ($value === null || ! $rule->breached($value)) {
            return null;
        }

        $summary = sprintf(
            '%s is %s (threshold %s %s).',
            $rule->type->label(),
            $measurement->format(),
            $rule->comparator?->symbol() ?? '',
            $this->formatThreshold($rule),
        );

        $event = $this->fire($rule, $summary, [
            'type' => $rule->type->value,
            'value' => $value,
            'unit' => $measurement->unit,
        ], $value);

        return $event;
    }

    /**
     * Record a firing and notify, unless the rule is still cooling down.
     *
     * Public because the event-shaped rules — a new issue, an incident —
     * are fired by the scan that discovered them rather than by a
     * measurement here.
     *
     * @param  array<string, mixed>  $context
     */
    public function fire(AlertRule $rule, string $summary, array $context = [], ?float $value = null): ?AlertEvent
    {
        if ($rule->inCooldown()) {
            return null;
        }

        /** @var AlertEvent $event */
        $event = $rule->events()->create([
            'value' => $value,
            'threshold' => $rule->threshold,
            'summary' => $summary,
            'context' => $context,
            'fired_at' => Carbon::now(),
        ]);

        $rule->forceFill(['last_fired_at' => Carbon::now()])->save();

        $this->notifier->send(
            new Notification(
                title: $rule->name,
                body: $summary,
                severity: Severity::Warning,
                facts: array_filter([
                    'Rule' => $rule->type->label(),
                    'Scope' => $this->scopeLabel($rule),
                    'Window' => $rule->window_minutes.' min',
                ], static fn (string $v): bool => $v !== ''),
            ),
            $rule->channels,
        );

        return $event;
    }

    private function formatThreshold(AlertRule $rule): string
    {
        $threshold = $rule->threshold;

        if ($threshold === null) {
            return '—';
        }

        return rtrim(rtrim(number_format($threshold, 2, '.', ''), '0'), '.').$rule->type->unit();
    }

    private function scopeLabel(AlertRule $rule): string
    {
        $scope = $rule->scope ?? [];
        $parts = array_filter([$scope['service'] ?? '', $scope['environment'] ?? '']);

        return $parts === [] ? 'all services' : implode(' · ', $parts);
    }
}
