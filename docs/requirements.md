---
title: Requirements
description: PHP, Laravel and package versions the resolver enforces
weight: 3
---

# Requirements

From `composer.json`, and nothing beyond what the resolver actually enforces.

| | |
| --- | --- |
| PHP | 8.3, 8.4 or 8.5 |
| Laravel | 12 or 13 (`illuminate/*`) |
| Dashboard | `cboxdk/laravel-telemetry-ui` ^2.7 |
| Database | Any Laravel-supported connection; the package ships four tables |

`cboxdk/laravel-telemetry-ui` is a hard dependency: this package reads
telemetry through its contracts, drivers and query IR. It does **not** need
the dashboard's UI to be served — see
[architecture](core-concepts/architecture.md) for why those are two different
things.

Through that dependency you also get `cboxdk/laravel-telemetry`, which
defines the span attributes and metric names the correlation relies on.
