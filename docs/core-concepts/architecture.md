---
title: Architecture
description: Three layers, one hard dependency, one soft one — and why the read-only promise survives
weight: 21
---

# Architecture

## Three layers, two dependencies

The dashboard package contains three things that are usually discussed as
one:

1. **A read layer** — contracts, drivers, the backend-neutral query IR.
2. **An analysis layer** — fingerprints, error reports, signal correlation.
3. **A presentation layer** — panels, the JSON API, the React app.

This package needs the first two and not the third. That split is the whole
design:

- **The read layer is a hard dependency.** Without it there is nothing to
  measure.
- **The presentation layer is a soft one.** Every class that knows the
  dashboard's UI exists lives under `src/Ui/`, registers behind a check, and
  is the first thing to go when the dashboard is not served.

An architecture test enforces the boundary: nothing outside `src/Ui/` may
import the dashboard's panel, HTTP or facade namespaces. The one exception
is `RequestScope`, which lives under an HTTP namespace but is a plain value
object — the query layer's scope primitive, built here from config in a cron
job with no request in sight.

The point is portability. If the read layer ever becomes its own package,
this one follows by changing an import, not by being rewritten.

## Where state lives, and where it does not

| | |
| --- | --- |
| Occurrences, stack traces, spans, metrics | Your telemetry stores. Read on demand, never copied |
| Status, assignee, snooze, cooldown, what we paged about | Four tables here |

This is why the dashboard's promise survives. `WritesToBackend` is about
**your telemetry stores** — Tempo, Loki, Prometheus, ClickHouse. A local
Laravel table is not one of those. The dashboard can still assert, and test,
that it never writes to your data.

It also means these tables are cheap. Delete them and you lose the decisions
your team made, not the telemetry.

## The pass

```
scan ──▶ IssueLedger      reads the window, folds it into the ledger
     ├─▶ IncidentCorrelator   groups what started together, names the cause
     ├─▶ IncidentRecorder     persists it under a stable signature
     └─▶ Notifier             announces only what is news
```

Everything in `Correlate/` is pure: it reads, it decides, it returns value
objects. Persistence happens afterwards, in one place. That is what lets
correlation re-run from scratch every pass and still produce a stable
result — see [incidents](incidents.md).
