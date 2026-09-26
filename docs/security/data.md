---
title: What this package stores
description: The four tables, what lands in them, and what never does
weight: 61
---

# What this package stores

## The four tables

| Table | Holds |
| --- | --- |
| `telemetry_issues` | Fingerprint, exception class, message, service, status, assignee, timestamps |
| `telemetry_incidents` | Signature, title, onset, the suspected cause and its evidence, member fingerprints |
| `telemetry_alert_rules` | Name, type, threshold, window, cooldown, scope, channels |
| `telemetry_alert_events` | Value, threshold, summary, context, when it fired |

## What is deliberately not stored

Occurrences, stack traces, spans, metrics and request bodies stay in your
telemetry stores and are read on demand. This package keeps decisions, not
data.

Two consequences worth knowing:

- Your telemetry retention still governs the raw data. An issue row can
  outlive the occurrences behind it, in which case the dashboard shows the
  status and no detail.
- Deleting these tables loses the decisions your team made — status,
  assignees, alert history — and nothing else.

## Personal data

The package copies an exception's **class, message and service** into the
issues table so a list can be rendered without querying the store. If your
exception messages embed personal data, that data is now in your database
as well as in your telemetry store, under your own retention. Nothing else
is copied; user identifiers, request bodies and attributes are not.

## Writing

This package writes to its own tables and to the notification channels you
configure. It does not write to your telemetry stores. That property belongs
to the dashboard package and is enforced there by its `WritesToBackend`
marker; installing this does not change it.

## Secrets

A Slack webhook URL and any webhook target come from config. Neither is
logged, including when the remote end rejects a delivery.

## Reporting a vulnerability

Use GitHub's private vulnerability reporting on
[the repository](https://github.com/cboxdk/laravel-telemetry-insights). It
is a best-effort project with no response-time commitment.
