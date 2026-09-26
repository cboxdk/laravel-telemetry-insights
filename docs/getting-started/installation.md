---
title: Installation
description: Composer, migrations, scheduling and the switches worth knowing
weight: 11
---

# Installation

```bash
composer require cboxdk/laravel-telemetry-insights
php artisan migrate
```

The service provider is discovered automatically. It registers commands,
schedules them, and adds two pages to the dashboard — all registrations, no
I/O, so an app that never runs a scan pays nothing for having this installed.

## Publishing

```bash
php artisan vendor:publish --tag=telemetry-insights-config
php artisan vendor:publish --tag=telemetry-insights-migrations
```

Neither is required. Publish the migrations only if you want to change the
schema; the table names are configurable without touching them.

## Scope

By default the scheduled work reads every service. Narrow it when one app
owns the dashboard for several:

```dotenv
TELEMETRY_INSIGHTS_SERVICE=checkout
TELEMETRY_INSIGHTS_ENVIRONMENT=production
TELEMETRY_INSIGHTS_WINDOW=15m
```

Keep the window comfortably longer than the interval the scan runs on, so a
slow ingest never drops an error between passes. The default pairs a 15
minute window with a 5 minute cron on purpose.

## Turning it off

```dotenv
TELEMETRY_INSIGHTS_ENABLED=false
```

No commands, no schedule, no dashboard pages. The tables stay where they are.

## Running headless

The package needs the dashboard's read layer, not its user interface. An app
that runs `TELEMETRY_UI_ENABLED=false` still gets the full scan, correlation
and alerting; only the two dashboard pages go away.
