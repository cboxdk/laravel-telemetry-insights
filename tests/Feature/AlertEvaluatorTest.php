<?php

declare(strict_types=1);

use Cbox\TelemetryInsights\Alerts\AlertEvaluator;
use Cbox\TelemetryInsights\Alerts\AlertType;
use Cbox\TelemetryInsights\Alerts\Comparator;
use Cbox\TelemetryInsights\Models\AlertEvent;
use Cbox\TelemetryInsights\Models\AlertRule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

function errorRateRule(float $threshold = 5.0): AlertRule
{
    $rule = new AlertRule;
    $rule->forceFill([
        'name' => 'Checkout error rate',
        'type' => AlertType::ErrorRate,
        'comparator' => Comparator::Above,
        'threshold' => $threshold,
        'window_minutes' => 5,
        'cooldown_minutes' => 30,
        'enabled' => true,
    ])->save();

    return $rule;
}

/** 8 requests in 100 came back 5xx. */
function fakeErrorRate(float $failed, float $ok): void
{
    Http::fake([
        'prometheus.test:9090/api/v1/query*' => Http::response(promVector([
            ['labels' => ['http_response_status_code' => '200'], 'value' => $ok],
            ['labels' => ['http_response_status_code' => '500'], 'value' => $failed],
        ])),
    ]);
}

it('fires when the measurement crosses the threshold, and notifies', function (): void {
    $notifier = $this->fakeNotifications();
    fakeErrorRate(failed: 8, ok: 92);

    $event = app(AlertEvaluator::class)->evaluate(errorRateRule(5.0));

    expect($event)->toBeInstanceOf(AlertEvent::class)
        ->and($event->value)->toBe(8.0)
        ->and($event->summary)->toContain('Error rate is 8%');

    $notifier->assertSentCount(1);
    $notifier->assertSent('Checkout error rate');
});

it('stays quiet when the measurement is within the threshold', function (): void {
    $notifier = $this->fakeNotifications();
    fakeErrorRate(failed: 1, ok: 99);

    $rule = errorRateRule(5.0);

    expect(app(AlertEvaluator::class)->evaluate($rule))->toBeNull()
        ->and($rule->refresh()->last_evaluated_at)->not->toBeNull()
        ->and($rule->last_fired_at)->toBeNull();

    $notifier->assertNothingSent();
});

it('does not page twice inside the cooldown', function (): void {
    $notifier = $this->fakeNotifications();
    fakeErrorRate(failed: 20, ok: 80);

    $rule = errorRateRule(5.0);
    $evaluator = app(AlertEvaluator::class);

    expect($evaluator->evaluate($rule))->toBeInstanceOf(AlertEvent::class)
        ->and($evaluator->evaluate($rule->refresh()))->toBeNull();

    $notifier->assertSentCount(1);
});

it('pages again once the cooldown has passed', function (): void {
    $notifier = $this->fakeNotifications();
    fakeErrorRate(failed: 20, ok: 80);

    $rule = errorRateRule(5.0);
    $rule->forceFill(['last_fired_at' => Carbon::now()->subMinutes(31)])->save();

    expect(app(AlertEvaluator::class)->evaluate($rule))->toBeInstanceOf(AlertEvent::class);
    $notifier->assertSentCount(1);
});

it('treats an unreachable backend as no measurement, never as a breach', function (): void {
    $notifier = $this->fakeNotifications();
    Http::fake(['prometheus.test:9090/*' => Http::response('down', 502)]);

    // "Below 1%" would be true of nothing at all — exactly the rule that
    // must not fire when the metrics store is unreachable.
    $rule = errorRateRule(1.0);
    $rule->forceFill(['comparator' => Comparator::Below])->save();

    expect(app(AlertEvaluator::class)->evaluate($rule))->toBeNull();
    $notifier->assertNothingSent();
});

it('treats an idle window as no measurement, not a zero error rate', function (): void {
    $notifier = $this->fakeNotifications();
    Http::fake(['prometheus.test:9090/api/v1/query*' => Http::response(promVector([]))]);

    $rule = errorRateRule(1.0);
    $rule->forceFill(['comparator' => Comparator::Below])->save();

    expect(app(AlertEvaluator::class)->evaluate($rule))->toBeNull();
    $notifier->assertNothingSent();
});
