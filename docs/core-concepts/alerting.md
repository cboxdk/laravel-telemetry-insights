---
title: Alerting
description: Rules that watch things that already mean something, with cooldowns
weight: 24
---

# Alerting

## Alert on meaning, not on numbers

Most alerting watches metrics. A metric threshold tells you a number moved;
it does not tell you what to do. This package weights the other way: the
rule types that matter most watch things that already mean something.

| Type | Fires when |
| --- | --- |
| `new_issue` | A fingerprint nobody has seen starts firing |
| `incident` | The correlator opened an incident |
| `issue_spike` | A known issue's rate jumps |
| `error_rate` | Share of requests answering 5xx, in % |
| `latency_p95` | Request p95, in ms |
| `throughput` | Requests per minute |
| `metric` | Any metric by name — the escape hatch |

## Two shapes of rule

**Measurement rules** are evaluated on a schedule by
`telemetry-insights:alerts`: measure, compare, fire.

**Event rules** (`new_issue`, `incident`) have nothing to measure. They fire
from `telemetry-insights:scan` when the event happens.

## Three separate decisions

Measured, breached, notifiable — each fails differently, so each is its own
step.

A measurement that could not be taken is **not** a value of zero. A
Prometheus that is unreachable, or a window with no traffic at all, returns
*unavailable*, and a rule watching for "error rate below 1%" does not fire
on it. This is the failure mode that quietly destroys trust in a monitor,
so it has its own tests.

## Cooldowns

A breach inside the cooldown is still recorded as an `AlertEvent`; it just
does not notify. The history stays complete while your phone stays quiet.
One broken thing should page you once.

## Defining a rule

```php
use Cbox\TelemetryInsights\Alerts\AlertType;
use Cbox\TelemetryInsights\Alerts\Comparator;
use Cbox\TelemetryInsights\Models\AlertRule;

AlertRule::create([
    'name' => 'Checkout p95',
    'type' => AlertType::LatencyP95,
    'comparator' => Comparator::Above,
    'threshold' => 800,          // ms — nobody sets a budget in seconds
    'window_minutes' => 10,
    'cooldown_minutes' => 30,
    'scope' => ['service' => 'checkout', 'environment' => 'production'],
    'channels' => ['slack'],
]);
```

Leave `channels` null to use the configured default set.

Measurements go through the backend-neutral query IR, so the same rule works
against Prometheus, Mimir or a SQL-backed store without knowing which is
behind it.
