---
title: Telemetry Insights
description: Stateful insights on top of the Cbox telemetry dashboard — deduplicated issues, incident correlation, alerting and digests
weight: 1
---

# Telemetry Insights

`cboxdk/laravel-telemetry-insights` adds the parts of observability that need
to **remember something**: an issue ledger, correlated incidents, alert rules
and periodic digests.

It is an add-on to
[`cboxdk/laravel-telemetry-ui`](https://github.com/cboxdk/laravel-telemetry-ui).
The dashboard stays what it is — a read-only window onto Tempo, Loki and
Prometheus. This package reads through the same query layer and keeps its own
small set of tables beside it.

## Why a separate package

The dashboard queries your telemetry stores and writes nothing to them. That
is a checkable property, not a slogan, and it is why you can point a desktop
client at production.

Some things cannot be answered that way. "Has this exception ever happened
before?" and "did this come back after we fixed it?" need a ledger. A
cooldown needs to know when you were last paged. So those live here, in
tables of our own — never in your telemetry stores, which stay untouched.

Install this and you get state. Do not, and the dashboard is exactly as
read-only as it was.

## What it does

- **An issue ledger** — every error group the dashboard already fingerprints,
  plus the decision your team made about it: open, resolved, ignored, snoozed.
  A resolved group that fires again is a **regression**, and says so.
- **Incident correlation** — when nine unrelated exceptions start within the
  same two minutes, that is one event, not nine. The correlator groups them
  and names the shared thing that failed. See
  [incidents](core-concepts/incidents.md).
- **Alerting** — rules that watch error rate, latency, throughput or any
  metric, plus event rules that fire on a new issue or a new incident, with
  cooldowns so one broken thing pages you once. See
  [alerting](core-concepts/alerting.md).
- **Digests** — a ranked list of what is worth looking at, and a Markdown
  brief you can hand to a coding assistant with the repository open. See
  [digests](core-concepts/digests.md).

## Documentation

- [Quickstart](quickstart.md) · [Requirements](requirements.md)
- Getting started:
  [installation](getting-started/installation.md) ·
  [testing](getting-started/testing.md)
- Core concepts:
  [architecture](core-concepts/architecture.md) ·
  [issues](core-concepts/issues.md) ·
  [incidents](core-concepts/incidents.md) ·
  [alerting](core-concepts/alerting.md) ·
  [digests](core-concepts/digests.md)
- Cookbook:
  [notify Slack](cookbook/slack.md) ·
  [hand findings to an assistant](cookbook/assistant-brief.md)
- Extension points:
  [notification channels](extension-points/channels.md) ·
  [your own correlation](extension-points/correlation.md)
- [Configuration reference](configuration/reference.md)
- [What this package stores](security/data.md)
