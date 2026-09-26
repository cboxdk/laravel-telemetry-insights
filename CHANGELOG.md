# Changelog

All notable changes to `cboxdk/laravel-telemetry-insights` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

First cut. Not released.

### Added

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
