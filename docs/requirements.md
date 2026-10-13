---
title: Requirements
description: PHP, Laravel and package versions the resolver enforces
weight: 3
---

# Requirements

From `composer.json`, and nothing beyond what the resolver actually enforces.

| | |
| --- | --- |
| PHP | 8.4 or 8.5 (`^8.4\|^8.5`) |
| Laravel | 12 or 13 (`illuminate/*`) |
| Dashboard | `cboxdk/laravel-telemetry-ui` ^3.1 (which requires `cboxdk/laravel-telemetry` ^3.5) |
| MCP | `laravel/mcp` ^1.0 |
| Database | Any Laravel-supported connection; the package ships six tables |

`cboxdk/laravel-telemetry-ui` is a hard dependency: this package reads
telemetry through its contracts, drivers and query IR. It does **not** need
the dashboard's UI to be served — see
[architecture](core-concepts/architecture.md) for why those are two different
things.

Through that dependency you also get `cboxdk/laravel-telemetry`, which
defines the span attributes and metric names the correlation relies on.

Two features also need `cboxdk/laravel-telemetry` ≥ 3.5 **in the
monitored apps** — the resolver only enforces it in the app running this
package:

| Feature | Needs laravel-telemetry |
| --- | --- |
| [Scheduled-task issues](core-concepts/scheduled-tasks.md) (the schedule gauges) | ≥ 3.5 |
| [Profiling](core-concepts/profiling.md) and ad-hoc directives | ≥ 3.5 |

Against an older monitored app both features find nothing to read or
nowhere to send, and say so; nothing else changes.
