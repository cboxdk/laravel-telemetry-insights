---
title: Hand findings to an assistant
description: From "this is slow in production" to the code that made it slow
weight: 32
---

# Hand findings to an assistant

Production tells you *what* is slow. The code tells you *why*. The gap
between those two is usually a person copying attributes into a search box.

```bash
php artisan telemetry-insights:digest --window=7d --markdown > findings.md
```

The brief is self-contained by design: window, scope, every finding with the
attributes that identify it, and an explicit ask. Paste it into an assistant
that has the repository open — no follow-up context needed.

```markdown
# Telemetry findings

Window: 2026-09-19 08:00 → 2026-09-26 08:00 UTC
Scope: checkout · production

Each finding below is something measured in production, with the
attributes that identify it. Please look for the cause in the code:
name the file or query you suspect, say why, and suggest a fix.

## 1. [Incident] redis cache-1:6379 — 9 error groups affected

100% of the affected groups called this cache, and the call failed in
9 of 9 traces inspected.

- **Started**: 2026-09-24 02:14
- **Error groups**: 9
- **Suspected cause**: redis cache-1:6379
- **Example trace**: 4458d523d54e5a50…

## 2. [Repeated query] Query called 1184 times

Individually fast (1.2ms) but run 1184 times, costing 1421ms in total —
the shape a query inside a loop makes.

- **Statement**: select * from `addresses` where `user_id` = ?
- **Calls**: 1184
```

## In CI

Nothing stops you piping this into a scheduled job that opens a pull request
with the brief in the body, so the week's findings arrive where the code
review already happens.

## Talking to the telemetry directly

For the other direction — an assistant querying your telemetry itself rather
than reading a summary — the dashboard package ships an MCP server with
tools for metrics, traces, logs and trace correlation. That is a capability
of `cboxdk/laravel-telemetry-ui`, not of this package.
