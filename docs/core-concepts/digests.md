---
title: Digests
description: What is worth looking at, ranked — and a brief that stands on its own
weight: 25
---

# Digests

A digest answers one question: *what should I look at this week?*

## Findings

| Kind | Where it comes from |
| --- | --- |
| Incident | The correlator |
| Regression | The issue ledger |
| New issue | The issue ledger |
| Slow query | Server-side span aggregation |
| Repeated query | The same, ranked by call count |
| Slow route | p95 by route |

Ranked by kind first, then by size. An incident outranks everything, because
it is the one finding that already explains several of the others.

Each finding carries the **common denominator** — the route, the statement,
the service, the instance — because "requests are slow" is true and useless,
while "`GET /checkout`, p95 2.4s, and one statement is 240ms of it" is
somewhere to start.

## Repeated queries

A statement that is individually fast but runs hundreds of times in one
window is reported separately from a slow one. That shape is what a query
inside a loop makes, and it is invisible to a threshold on duration.

This finding needs a store that can aggregate spans server-side. A backend
without that capability simply contributes nothing here — every source in a
digest fails open on its own, because a partial digest beats no digest.

## The brief

```bash
php artisan telemetry-insights:digest --window=7d --markdown
```

Prints the findings as one self-contained Markdown document and nothing
else, so it pipes. See
[hand findings to an assistant](../cookbook/assistant-brief.md).

## Thresholds

Under `telemetry-insights.digest`: `slow_route_ms` (1000),
`slow_query_ms` (100), `repeated_query_calls` (100), and `limit` (5) per
kind. Tune them to your app — a threshold that is never crossed produces an
empty digest, and one that is always crossed produces noise.
