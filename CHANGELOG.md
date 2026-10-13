# Changelog

All notable changes to `cboxdk/laravel-telemetry-insights` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.1] - 2026-09-27

### Fixed

- **Booting no longer builds the host's schedule.** The three jobs were
  registered from an `app->booted()` hook that resolved `Schedule`, and
  resolving it runs the host's entire `schedule()` method. A host is entitled
  to build its schedule from state — one asks its jobs table whether a job is
  already queued before scheduling it — so this ran a database query in every
  console command, `package:discover` during `composer install` included,
  where there is no database yet. Installing this package broke the install
  that was installing it, with a `Connection refused` from a stack trace that
  named the host's kernel and not this package.

  `callAfterResolving()` registers the same work against whoever resolves the
  scheduler for their own reasons, and costs nothing when nobody does.

## [Unreleased]

Needs a migration (`php artisan migrate`): seven columns on the issues
table, a table for issue history and one for profiling runs.

### Added

- **Archive until escalating.** A new `archived` status for error groups:
  quiet until the group's hourly volume goes past limits learned from its
  previous week — over the spike limit (95th-percentile hour × 3) for two
  hours running, or over the burst limit (worst hour × 1.5) in any one
  hour, so a nightly job's usual burst reopens nothing. An escalation
  reopens the issue, is announced (`announce.escalations`), fires the new
  `escalation` rule type and is written to the history. From the console
  (`issue archive`), the API (`action: archive`), the issues panel and the
  `archive_issue` agent tool.
- **Stale issues resolve themselves.** An open error group with no
  occurrence for `stale.resolve_after_days` (14) is resolved by the scan and
  recorded as automatic; a later occurrence comes back as a regression.
  `stale.enabled = false` turns it off.
- **Profiling rotation** (off by default). On a schedule, the costliest
  routes (total time, or p95 × request rate) not profiled in the last day
  get a time-limited `route` directive with a CPU profile on the monitored
  app — in-process when `cboxdk/laravel-telemetry` with directives runs
  here, otherwise over its signed endpoint (`profiling.targets`). Every
  run is kept in `telemetry_profiling_runs`, shown on a Profiling page, by
  `telemetry-insights:profiling --list` and by the `profiling_rotation`
  agent tool.
- **Ad-hoc directives.** `telemetry-insights:watch --user=42 --minutes=120
  --profile` and the `request_directive` agent tool (gated like the other
  write tools) keep one slice of a monitored app's traffic for a while.

- **Scheduled-task issues.** Each scan reads the schedule gauges
  `cboxdk/laravel-telemetry` publishes (schedule monitoring, unreleased at
  the time of writing) and turns what is wrong into issues: a stopped
  scheduler, a task that missed its slot, one that is overrunning, and one
  that failed N times in a row (`failure_threshold`, default 1). They are
  conditions, so the scan resolves them itself when they clear — a missed
  task resolves when it runs again — and reopens them as regressions when
  a new episode starts. Thresholds are configurable under
  `scheduled_tasks`, with per-task overrides by name or `*` pattern.
  Announced (`announce.scheduled_tasks`, recoveries via
  `announce.recoveries`), a new `scheduled_task` event rule type, and a
  "Scheduled task" digest finding that reports the current state.
- **Agent tools.** `list_issues`, `show_issue`, `resolve_issue`,
  `ignore_issue` and `reopen_issue`, registered on the dashboard's MCP
  server. Compact output with totals and paging; `show_issue` is Markdown
  with the latest trace and the last day's occurrences and affected users.
  Reads follow the dashboard's tenancy lock; changes need
  `manageTelemetryUi`. `mcp.writes = false` leaves the read tools only.
- **Issue history.** Every decision — from the terminal, the API, an
  agent, the scan or your own code — is written to
  `telemetry_issue_events` with who made it, through which door, and an
  optional comment (`--comment`, the API's `comment`, the tools'
  `comment`). `IssueActions` takes an optional `Attribution`.
- Issues carry a `kind`, and the latest trace id of an error group. The
  API and list command show a title (`Scheduled task missed:
  reports:nightly`) and filter by `kind`.

### Changed

- `laravel/mcp` is now a declared dependency (it already came with the
  dashboard).
- The issues panel and `telemetry-insights:issues` label the column
  "Issue" rather than "Exception", since not every issue is one.

### Fixed

- **The issues panel and the HTTP API ignored the dashboard's tenancy
  lock.** A viewer locked to some services saw — and could change — every
  service's issues. Both now narrow through the lock and fail closed, as
  the agent tools do; an issue outside it is a 404.

## [1.0.0] - 2026-09-27

First release. `cboxdk/laravel-telemetry-ui` reads the backends and shows you
what is happening; this package remembers. It keeps the state a dashboard
cannot: which error groups the team has already decided about, which ones
started together and what they have in common, and what is worth waking
someone for.

It needs a database of its own (four tables) and
`cboxdk/laravel-telemetry-ui` ^3.0. Nothing in it runs unless you schedule
the scan.

The entries below are the development history of that first cut; the fixes
name faults that were never in a released version.

### Fixed

- **A scan with a service configured crashed.** `hostPressure()` built its
  Prometheus labels from dimension names `ScopeLabels` rejects, so an
  install that had scoped its scan (`TELEMETRY_INSIGHTS_SERVICE=…`) hit an
  uncaught exception the moment a burst had no dependency or deploy cause.
  Every test scanned every service, which is why it went unnoticed.
- **A digest no longer marks everything as seen.** `DigestBuilder` folded
  the window into the ledger as it read it, so running
  `telemetry-insights:digest` before `:scan` left the scan with nothing new
  to announce — a report quietly disarmed the alerting. The ledger now
  separates `record()` (the scan, writes) from `preview()` (the digest,
  does not).
- **A regression fired the "new issue" rule**, announced as `New issue:`.
  It has its own `AlertType::Regression` now: something that escaped a fix
  is a different event from something nobody has seen.
- **The ledger read one row at a time.** A window with a hundred error
  groups meant a hundred SELECTs on a job that runs every few minutes; it
  is one query now.
- An event rule fired with only the first change in its context. It now
  names the first and counts the rest, in one firing per pass.

### Added

- **A failing dependency speaks for itself.** When the dashboard has
  discovered an exporter watching the address the app dialled, the incident
  asks it what it saw around the onset and appends whatever was materially
  outside its normal band — "its own exporter agrees: memory used was 3.97
  against a usual 1.20". Corroboration from the dependency itself is the
  strongest evidence available, so a cause backed by it is reported with
  high confidence. Needs `cboxdk/laravel-telemetry-ui` ^2.7.
- **Incident correlation.** Error groups that start within the same short
  window are folded into one incident, and the shared thing that failed is
  named: a downstream present in most of the burst's traces *and* erroring
  in them, a deploy shortly before onset, or a host signal outside its
  normal band. Each cause carries the sentence that produced it and a coarse
  confidence. Identity is keyed on the cause and the hour, so a burst that
  grows keeps its signature instead of filing a new incident every pass.
- **Issue ledger.** One row per error-group fingerprint holding the team's
  decision — open, resolved, ignored, snoozed — as an overlay on the
  telemetry store, which keeps the occurrences. Makes two facts answerable
  that a store alone cannot: a fingerprint never seen before, and one that
  fired again after being resolved (a regression, which reopens it).
- **Alerting.** `AlertRule` / `AlertEvent` with event-shaped types (new
  issue, incident) fired by the scan, and measured types (error rate, p95,
  throughput, any metric) evaluated on a schedule through the
  backend-neutral query IR, so one rule works on any supported store.
  Cooldowns record the firing without notifying. An unavailable measurement
  is never a breach — an unreachable Prometheus or an idle window does not
  fire a "below" rule.
- **Digests.** Ranked findings — incidents, regressions, new issues, slow
  routes, slow queries, and queries that are individually fast but run
  hundreds of times — each with the attributes that identify it.
  `--markdown` prints them as one self-contained brief to hand to a coding
  assistant.
- **Issue actions.** Resolve, reopen, ignore, snooze and assign, as a
  service and as `telemetry-insights:issue` (a unique fingerprint prefix is
  enough; an ambiguous one is refused). Without these, regression detection
  was unreachable: nothing could be resolved, so nothing could come back.
  `telemetry-insights:issues` lists the working list.
- **Spike detection.** A known issue firing several times harder than in
  the window immediately before it, with an occurrence floor so small
  numbers cannot produce a large multiple, and no spike for a fingerprint
  with no baseline (that is a new issue). Costs an extra read, so it only
  runs when a rule is watching. An unreadable baseline yields no spike
  rather than an infinite one.
- **Notifications.** Log, Slack (Block Kit over an incoming webhook) and
  generic webhook channels behind a `NotifiesChannel` contract; channels
  never throw, so a dead pager cannot take down the pass that found the
  problem. A digest's Markdown brief rides along to the channels with room
  for it.
- **HTTP API.** `GET`/`POST` for issues and incidents under
  `{path}/api/v2/insights`, mounted inside the dashboard's own route group
  so its gate, middleware and throttle apply unchanged. Reading takes
  `viewTelemetryUi`; every write takes the separate `manageTelemetryUi`,
  checked in the controller. There so a host can build the screen this
  package does not ship.
- **Row actions on the dashboard pages.** Resolve, snooze or ignore an
  issue, acknowledge or resolve an incident, from a menu on the row —
  offered by the payload, authorized by the endpoint, so a viewer who can
  see an issue still cannot close it. Only the actions that apply to the
  current status are offered. Needs `cboxdk/laravel-telemetry-ui` ^2.6.1,
  which added `Ui::action()` and made its menu visible.
- **Dashboard pages.** Incidents and Issues tables, registered into
  `cboxdk/laravel-telemetry-ui` when it is served. Everything that knows the
  dashboard's UI exists is confined to `src/Ui/`, enforced by an
  architecture test, so the read layer can be extracted later without
  rewriting this package.
- **Testing.** `InteractsWithInsights` plus `FakeNotifier`, used by the
  package's own suite.
