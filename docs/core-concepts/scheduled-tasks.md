---
title: Scheduled tasks
description: The runs that never happened — missed, overrunning and failing tasks, and a stopped scheduler
weight: 23
---

# Scheduled tasks

The scheduled-task failures that hurt most are the runs that never
happened: the cron entry lost in a server move, the `schedule:work`
process nobody restarted, the nightly export that has been hanging since
Tuesday. No exception is thrown for any of them, so the error ledger
never hears about them.

`cboxdk/laravel-telemetry` (with `instrument.schedule_monitoring`, on by
default) publishes a few gauges about the schedule, and each pass of
`telemetry-insights:scan` reads them through the dashboard's metrics
connector and turns what is wrong into issues.

## What becomes an issue

| Kind | Opens when | Clears when |
| --- | --- | --- |
| `scheduler_stopped` | `schedule:run` has not started for `scheduler_stopped_after_seconds` (180) | it starts again |
| `scheduled_task_missed` | a task was due more than `missed_after_seconds` (300) before it last started or was skipped, and `schedule:run` listed it in the last `listed_within_seconds` (600) | it runs again, or leaves the schedule |
| `scheduled_task_overrunning` | a run started more than `overrun_after_seconds` (3600) ago and has not finished | the run finishes |
| `scheduled_task_failing` | it failed `failure_threshold` (1) times **in a row** | it succeeds |

Each gauge is read as the maximum across hosts, so an `onOneServer()` task
is not "missed" by the servers that lost the lock. A task that has never
run since monitoring was enabled has nothing to compare against and is not
judged until it has.

"In a row" means failures since the task last succeeded, counting
`runInBackground()` runs too. A task that failed earlier in a long window
but has succeeded since is not failing. Counting that
costs one read per failing task, so it only happens for tasks that failed
in the scan's window, and at most `max_failure_probes` (20) of them per
pass.

laravel-telemetry 3.5 publishes the failure counters at zero for every
listed task, so the first failure is an increase like any other. **Against
an older core, a task's very first failure is invisible** — a counter that
appears already at 1 has no increase — and the issue opens on the second.

## Conditions, not occurrences

An error group is an occurrence: it happened, and only a person can call it
fixed. A scheduled-task issue is a condition, and it holds until the task
runs again — so the scan **resolves it on its own** when the condition
clears, and writes that to the issue's history as done automatically
(`resolved_by` is `telemetry-insights`). A recovery is announced when the
problem itself was.

What counts as *back again* (a regression, reopened and announced):

- after the scan resolved it, any recurrence — the condition cleared in
  between, so this is a new episode;
- after a **person** resolved it, only an episode that began afterwards. A
  task resolved while still behind stays resolved until it runs and then
  misses again. Resolving says "I know about this one"; the next pass
  should not contradict it.

After a person resolved a **failing** task, any failure after the resolve
reopens it too: a failure has a time, so "it failed again after you called
it fixed" is decidable.

Ignored and snoozed issues (until the snooze runs out) are kept current but never announced, reopened
or resolved by the scan.

The issue's fingerprint is derived from kind, service, environment and
task, so the same task missing on the next pass is the same issue.

## What the scan will not decide

Absence of a problem only means "recovered" where the scan actually
looked. While a service's scheduler is stopped its due times are stale, so
its missed tasks are neither confirmed nor resolved — they wait for the
scheduler to come back. A Prometheus that does not answer changes nothing
at all.

One consequence worth planning for: **the scan is itself a scheduled job.**
If it runs on the scheduler that stopped, nothing reports the stop. Run the
scan for a fleet from somewhere else — another service, another host, or a
plain cron entry — when the stopped-scheduler check matters to you.

## Tuning

Defaults live under `scheduled_tasks` in the config. Override them per task
by name or `*` pattern; an exact name wins over a pattern, and among
patterns the first listed wins:

```php
'scheduled_tasks' => [
    // ...
    'tasks' => [
        'reports:nightly' => ['overrun_after_seconds' => 4 * 3600],
        'sync:*' => ['failure_threshold' => 3, 'missed_after_seconds' => 900],
        'telescope:prune' => ['enabled' => false],
    ],
],
```

The task name is the `task` label `laravel-telemetry` publishes — the
command name, or the description you gave a closure.

## Announcements, rules and the digest

- New and returning problems are announced (`announce.scheduled_tasks`);
  a stopped scheduler and a returning problem are critical, the rest
  warnings. Recoveries go out at info level (`announce.recoveries`).
- An `AlertType::ScheduledTask` rule fires once per pass when there is
  something new, like the other event rules — see
  [alerting](alerting.md).
- The digest lists what is wrong with the schedule **now**, not only what
  changed: a task that stopped running on Tuesday is still the most
  important thing in Friday's digest. Ignored and snoozed problems stay
  out.

## Requirements

`cboxdk/laravel-telemetry` ≥ 3.5 with schedule monitoring (the gauges
`schedule_run_last_seen_seconds` and `schedule_task_*_seconds`, and the
`schedule_tasks_failed_total` and `schedule_background_tasks_failed_total`
counters), a metric store shared by the hosts
that run the scheduler, and a dashboard metrics connection that scrapes it.
With no gauges in the store the check costs one query and finds nothing.
