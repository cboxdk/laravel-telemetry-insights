---
title: Agent tools
description: The issue ledger over MCP — list, show, resolve, ignore and reopen from an assistant
weight: 44
---

# Agent tools

`cboxdk/laravel-telemetry-ui` serves an MCP server — read-only tools over
the traces, logs and metrics. This package adds the issue ledger to it, so
an assistant that can already read what happened can also see what the
team has decided about it, and make a decision of its own on the team's
behalf.

There is nothing to wire up: the tools are registered on the dashboard's
server when both packages are enabled.

```bash
php artisan mcp:start telemetry-ui      # stdio, for a local assistant
```

or the dashboard's web endpoint, when `telemetry-ui.mcp.web.enabled` is on.

## The tools

| Tool | Does |
| --- | --- |
| `list_issues` | The working list, open first. Filters: `status` (effective — a lapsed snooze is open), `kind` (one kind, or `scheduled` for every scheduled-task kind), `service`. Pages with `limit`/`offset`; answers `total` and `next_offset` |
| `show_issue` | One issue as Markdown: fingerprint, kind, status, first and last seen, resolution, the latest trace id and — for an error group — occurrences and affected users over the last 24 hours, read live from the store. Ends with the history |
| `resolve_issue` | Mark it fixed, with an optional `comment` and `release` |
| `ignore_issue` | Never announce it again, with an optional `comment` |
| `reopen_issue` | Back on the working list, with an optional `comment` |
| `archive_issue` | Quiet until it escalates (error groups only) — see [issues](../core-concepts/issues.md#archive-until-escalating) |
| `profiling_rotation` | Read-only: what the [profiling rotation](../core-concepts/profiling.md) and ad-hoc directives placed, which are active, the settings; `next=true` ranks the next picks |
| `request_directive` | Keep (and optionally profile) one slice of a monitored app's traffic for a while: `match` route/path/user/context/job, `value`, `key` for context, `minutes`, `profile`, `reason`, `service` |

Every tool accepts a unique prefix of a fingerprint; an ambiguous one is
refused rather than guessed at. Output is built for a reader that pays per
token: empty keys are left out, long messages are cut in the list, and
`show_issue` has the rest.

## A skill for the agent (Laravel Boost)

The package ships an agent skill, `telemetry-insights-triage`, at
`resources/boost/skills/telemetry-insights-triage/SKILL.md`, the path
[Laravel Boost](https://laravel.com/docs/boost#third-party-package-skills)
reads in third-party packages. It teaches the agent the triage loop: the
open list, `show_issue`, the latest trace into telemetry-ui's
`trace_summary`, and which decision tool fits, with a comment written for
the next reader. It also says when not to change a status: only when asked
to act on the ledger, never while merely investigating.

Run `php artisan boost:install` (Boost 2.x), choose *Agent Skills*, and
tick `cboxdk/laravel-telemetry-insights` in the third-party list. Boost
only reads direct dependencies, so the dashboard's `telemetry-ui-mcp` skill
and laravel-telemetry's own skills come with them only when the app
requires `cboxdk/laravel-telemetry-ui` and `cboxdk/laravel-telemetry`
itself.

## Who did it

Every change is written to the issue's [history](../core-concepts/issues.md#history)
as made **via agent**, with the comment the agent gave and — when the
transport has a signed-in user — that user's email, name or id.
`show_issue` prints the history, so the next reader sees "Resolved via
agent by sylvester@example.com — 'retry added in v2.4.1'" rather than a
status that changed with no explanation.

## Authorization

- **Reading** follows the dashboard's tenancy lock: a viewer locked to some
  services or environments sees only those issues, and a lock to nothing
  shows nothing. Error groups carry no environment, so a viewer whose
  *environments* are locked does not see them — it cannot be shown they
  are theirs.
- **Changing** takes `manageTelemetryUi`, the ability the dashboard's own
  write API checks, evaluated for the transport's user. With no user, only
  the local stdio server is trusted — it runs under artisan, for someone
  who could run `telemetry-insights:issue` in the same shell. An HTTP
  request with no user is refused.
- `request_directive` is a write too, with the same gate. A viewer locked
  to some services may only target those, and must name one. A viewer
  locked to some **environments** cannot request directives at all —
  targets are keyed by service, so a directive cannot be held to an
  environment — and sees no profiling runs in `profiling_rotation` or on
  the Profiling page.
- `telemetry-insights.mcp.writes = false` registers the read tools only;
  `telemetry-insights.mcp.enabled = false` registers none.

## Headless

The tools ride on the dashboard's MCP server, so an app running
`TELEMETRY_UI_ENABLED=false` has none — the same as the
[HTTP API](http-api.md). The `IssueActions` service and the artisan
commands remain.
