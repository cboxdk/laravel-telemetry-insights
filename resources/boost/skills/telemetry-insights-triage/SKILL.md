---
name: telemetry-insights-triage
description: "Triage production errors and scheduled-task problems with the cboxdk/laravel-telemetry-insights issue ledger over MCP: list and show issues, open the latest trace, then resolve, ignore, reopen or archive them with a comment, and request a directive or check the profiling rotation. Use when asked what is broken in production, to work through open issues, to mark an issue fixed after a change, or to profile a route."
license: MIT
metadata:
  author: cboxdk
---

# Triaging issues with telemetry-insights

## When to use this skill

Use it when the `telemetry-ui` MCP server is connected and laravel-telemetry-insights
is installed: its tools ride on that server (`php artisan mcp:start telemetry-ui`).
An issue is an error group (same fingerprint the dashboard groups by) or a
scheduled-task problem, plus the decision the team made about it.

If the tools below are missing: telemetry-ui may be disabled
(`TELEMETRY_UI_ENABLED=false`), or `telemetry-insights.mcp.enabled` is off.
Without `telemetry-insights.mcp.writes`, only the read tools exist. Say which,
do not work around it.

## The tools

| Tool | Use it to | Key arguments |
|---|---|---|
| `list_issues` | the working list, open first, then most recently seen | `status`, `kind`, `service`, `limit` (20), `offset` |
| `show_issue` | one issue as Markdown: status, first/last seen, latest trace id, 24h occurrences and affected users, decision history | `fingerprint` |
| `resolve_issue` | mark it fixed; a later occurrence reopens it as a regression | `fingerprint`, `comment`, `release` |
| `ignore_issue` | known and not worth acting on; never announced again | `fingerprint`, `comment` |
| `reopen_issue` | put a resolved, ignored or snoozed issue back on the list | `fingerprint`, `comment` |
| `archive_issue` | quiet until its volume escalates (error groups only) | `fingerprint`, `comment` |
| `profiling_rotation` | what the profiling rotation and ad-hoc directives placed, which are active | `limit`, `next` |
| `request_directive` | keep every trace (and optionally a CPU profile) of one slice of traffic for a while | `match`, `value`, `key`, `minutes`, `profile`, `reason`, `service` |

`status` is one of `open`, `resolved`, `ignored`, `snoozed`, `archived` (a
lapsed snooze counts as open). `kind` is `exception`, `scheduler_stopped`,
`scheduled_task_missed`, `scheduled_task_overrunning`, `scheduled_task_failing`,
or `scheduled` for every scheduled-task kind. Every tool takes a unique
prefix of a fingerprint; an ambiguous prefix is refused, so pass more of it.

## Workflow: work through what is open

1. `list_issues` with `status: "open"` (add `service` when the user names one).
   Page with `offset` = the `next_offset` you got back.
2. `show_issue` on each one worth a look. Read the history first: someone may
   already have decided something about it.
3. Take the latest trace id from `show_issue` to the telemetry-ui tools:
   `trace_summary` (errors with file:line first) or `trace_markdown` for a
   write-up. Then find the cause in the code.
4. Report back per issue: what it is, how often, who it hits, the suspected
   cause with file and line, and the decision you propose.

## Recording a decision

Every change is written to the issue history as made via agent, with your
`comment` and the transport's user. Write the comment for the next human
reader: why, and what changed.

- **Fixed in code you just changed**: `resolve_issue` with a `comment` naming
  the fix and `release` once it is known (e.g. `v2.4.1`). Do not resolve
  something you have not fixed or verified.
- **Noise the team has agreed to live with**: `ignore_issue`, with the reason.
- **Real but low volume, and you want to hear if it grows**: `archive_issue`.
- **Resolved too early**: `reopen_issue`.

Only change status when the user asked you to act on the ledger, not while
merely investigating. There is no snooze or assign tool; for those, give the
user the command: `php artisan telemetry-insights:issue snooze <fingerprint> --until="3 days"`
or `... assign <fingerprint> --to=<who>`.

Changes need the `manageTelemetryUi` ability for the transport's user; the
local stdio server is trusted. A refusal means the user lacks it; report it.

## Profiling a slow route

- `profiling_rotation` with `next: true` shows what was profiled recently and
  ranks the routes the next rotation would pick.
- `request_directive` with `match: "route"`, `value: "orders.*"`,
  `minutes: 30`, `profile: true` and a `reason` keeps every matching trace and
  a CPU profile, then expires (24h max). Kept traces carry
  `telemetry.directive = <id>`. Keep it narrow and short: every matching
  request is fully sampled.

## Limits to respect

- Reads follow the dashboard's scope lock: a viewer locked to some services
  or environments sees only those issues. Error groups carry no environment,
  so an environment-locked viewer sees none of them. An empty list can mean
  "locked", not "healthy".
- A weekly summary for a human or a PR is
  `php artisan telemetry-insights:digest --window=7d --markdown`.
