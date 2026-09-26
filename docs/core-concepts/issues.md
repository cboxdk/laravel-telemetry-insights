---
title: Issues
description: The ledger of what you have seen, and what makes something news
weight: 22
---

# Issues

An issue is one error group — the same fingerprint the dashboard already
groups occurrences by — plus the decision your team made about it.

The row is an **overlay**, not a copy. Occurrences, stack traces and counts
are read from the telemetry store every time. Delete the row and you lose
the status, nothing else.

## Status

| | |
| --- | --- |
| `open` | Nobody has decided anything yet |
| `resolved` | Believed fixed. A later occurrence reopens it |
| `ignored` | Known, deliberately not worth acting on. Never notifies |
| `snoozed` | Quiet until `snoozed_until`, then open again |

A lapsed snooze is open again the moment it lapses — `effectiveStatus()`
works it out on read, so nothing has to sweep the table.

## What is news

A window contains hundreds of occurrences and nearly all of them are the
same things that were there an hour ago. A monitor that announces all of
them gets muted inside a week, so only two things are news:

- **New** — a fingerprint this install has never recorded. This is the only
  fact the telemetry store genuinely cannot answer: it can tell you an
  exception happened, not that it never happened before.
- **Regression** — a group marked resolved that has fired again *since* it
  was resolved. The occurrence must be newer than `resolved_at`, so
  re-reading an old window never counts as a regression. A regression
  reopens the issue automatically.

Everything else is *recurring*, recorded silently.

Announcements respect status: an ignored or snoozed issue is never
announced, however hard it is firing.

## Recording a pass

```php
use Cbox\TelemetryInsights\Issues\IssueLedger;
use Cbox\TelemetryUi\Http\Api\RequestScope;

$changes = app(IssueLedger::class)->record(new RequestScope(period: '1h'));

foreach ($changes as $change) {
    if ($change->kind->isNews()) {
        // $change->issue, $change->kind, $change->count
    }
}
```

A backend that will not answer yields no changes rather than an exception:
a monitoring pass must not be the thing that breaks.

## Proving a regression

Set `resolved_in_release` when you resolve an issue and the release rides
along into the digest, so a regression says which fix it escaped.
