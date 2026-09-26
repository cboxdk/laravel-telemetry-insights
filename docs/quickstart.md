---
title: Quickstart
description: From composer require to a correlated incident in one read
weight: 2
---

# Quickstart

```bash
composer require cboxdk/laravel-telemetry-insights
php artisan migrate
```

That is the whole install. The package finds your existing dashboard
configuration, so there are no connections to set up.

## Take one pass

```bash
php artisan telemetry-insights:scan --window=1h
```

Reads the last hour, records every error group it finds, correlates the ones
that started together, and announces what is new. The first run records
everything and announces nothing you have not asked for — see
[issues](core-concepts/issues.md).

Out of the box notifications go to your log. To send them somewhere people
read, see [notify Slack](cookbook/slack.md).

## See what is worth your time

```bash
php artisan telemetry-insights:digest --window=7d
```

```
Incident: redis cache-1:6379 — 9 error groups affected (and 4 other findings)

  [Incident]     redis cache-1:6379 — 9 error groups affected
  [Regression]   PaymentDeclined is back
  [Slow query]   Slow query averaging 240.5ms
  [Slow route]   Slow route /checkout
```

Add `--markdown` to get the same thing as a brief that stands on its own,
ready to paste into an assistant that has the codebase open. See
[hand findings to an assistant](cookbook/assistant-brief.md).

## Let it run

The three jobs register themselves on your scheduler with sensible crons, so
`schedule:run` is all that is needed. Change or disable them under
`telemetry-insights.schedule`.

| Command | Default | What it does |
| --- | --- | --- |
| `telemetry-insights:scan` | every 5 min | Ledger, correlation, announcements |
| `telemetry-insights:alerts` | every 5 min | Evaluate measurement rules |
| `telemetry-insights:digest` | Mondays 08:00 | The weekly findings |

## Add an alert

```php
use Cbox\TelemetryInsights\Alerts\AlertType;
use Cbox\TelemetryInsights\Alerts\Comparator;
use Cbox\TelemetryInsights\Models\AlertRule;

AlertRule::create([
    'name' => 'Checkout error rate',
    'type' => AlertType::ErrorRate,
    'comparator' => Comparator::Above,
    'threshold' => 2.0,
    'window_minutes' => 10,
    'cooldown_minutes' => 30,
    'scope' => ['service' => 'checkout'],
    'channels' => ['slack'],
]);
```
