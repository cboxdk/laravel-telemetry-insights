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

## Acting on one

```bash
php artisan telemetry-insights:issues                       # the working list
php artisan telemetry-insights:issue resolve aaaa0000 --release=v2.4.1
php artisan telemetry-insights:issue snooze  aaaa0000 --until="3 days"
php artisan telemetry-insights:issue ignore  aaaa0000
php artisan telemetry-insights:issue assign  aaaa0000 --to=sylvester
php artisan telemetry-insights:issue reopen  aaaa0000
```

A unique prefix of the fingerprint is enough; an ambiguous one is refused
rather than guessed at.

Resolving is what makes regression detection mean anything — until a group
has been called fixed, it can never come back. Record the release the fix
shipped in and a later occurrence says which fix it escaped.

From your own code the same actions are one service:

```php
use Cbox\TelemetryInsights\Issues\IssueActions;

app(IssueActions::class)->resolve($issue, by: $user->name, release: 'v2.4.1');
```

The dashboard pages this package adds are **read-only** lists. Changing an
issue's status is the command, the service, or a screen of your own built
on top of it.

## Spikes

A known issue firing materially harder than in the window immediately
before it — not against a long-run average, because a rate that doubled in
the last ten minutes is the thing worth interrupting someone for, while one
creeping up all month belongs in the digest.

Two guards keep it honest: an issue must reach five occurrences before a
multiple means anything (one becoming three is not a 3× spike), and a
fingerprint with no baseline is a *new* issue, which is already its own
announcement.

Detecting a spike costs one extra read, so it only happens when a rule is
actually watching for one. See [alerting](alerting.md).
