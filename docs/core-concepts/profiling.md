---
title: Profiling
description: The costliest routes, profiled in turn — and one slice of traffic, kept on demand
weight: 26
---

# Profiling

A sample rate is right for the fleet and wrong for the one slow route
everyone is asking about: at 10% sampling, most of its slow requests are
gone before anyone looks. `cboxdk/laravel-telemetry` has **directives** for
this — time-limited rules in the monitored app that keep every trace of one
slice of traffic (a route, path, user, context value or job), with its
detail spans and, optionally, a CPU profile, and then turn themselves off.

This package places directives in two ways:

- **The rotation**, on a schedule: profile the routes that cost the most,
  a few at a time, in turn.
- **Ad hoc**, from the terminal or an agent: "keep user 42 for two hours,
  with a profile".

Every directive is written to `telemetry_profiling_runs`, shown on the
dashboard's **Profiling** page, by `telemetry-insights:profiling --list`, and
by the read-only `profiling_rotation` agent tool. Traces a directive kept
carry `telemetry.directive=<id>`.

## The rotation

Off by default. Turn it on with `TELEMETRY_INSIGHTS_PROFILING=true`, and
`telemetry-insights:profiling` is scheduled (`schedule.profiling`, every
four hours). Each pass:

1. ranks routes by **impact** from the request-duration histogram over
   `lookback` (24h): `total_time` — seconds spent in the route, the work it
   costs the fleet whether slow or merely busy — or `p95_rate`, p95 latency
   × requests per second;
2. drops the ones profiled in the last `cooldown_hours` (24) — this is what
   makes it rotate;
3. places a `route` directive with `profile` on the top `routes` (3), for
   `minutes` (15), `created_by` `insights`, the reason naming the rank and
   the impact.

The `http_route` label is sent as the route's URI template without its
leading slash, which is what a `route` directive matches in Laravel.

```bash
php artisan telemetry-insights:profiling --dry-run   # what it would pick
php artisan telemetry-insights:profiling --list      # what it did
php artisan telemetry-insights:profiling --force     # one pass, even while off
```

### What it costs

For those minutes, **every request on the chosen routes is kept and
CPU-profiled** — sampled at 100%, whatever the app's sample rate. That is
the point: a profile of the slow path rather than of whatever sampling
happened to keep. It is also real load on the trace store and on the
requests themselves (the profiler's overhead). Three routes for fifteen
minutes every four hours is about 6% of the day per route at most;
shorten `minutes`, lower `routes` or lengthen the schedule if the routes
are very busy.

## Ad hoc

```bash
php artisan telemetry-insights:watch --user=42 --minutes=120 --profile --reason="ticket 881" --service=shop
php artisan telemetry-insights:watch --route="checkout.*" --minutes=15
php artisan telemetry-insights:watch --context=tenant.id=7 --service=shop
```

Exactly one of `--route`, `--path`, `--user`, `--context` (`key=value`) or
`--job`. The agent tool `request_directive` does the same, gated like the
other write tools (see [agent tools](../extension-points/agent-tools.md)).

The monitored app enforces its own limits: at most
`telemetry.directives.max` active, none longer than
`telemetry.directives.max_minutes`.

## Reaching the app

- **Over HTTP**, for an app elsewhere. Set `TELEMETRY_DIRECTIVES_SECRET` in
  that app and list it here:

  ```php
  'profiling' => [
      'targets' => [
          'shop' => ['url' => env('SHOP_URL'), 'secret' => env('SHOP_DIRECTIVES_SECRET')],
      ],
  ],
  ```

  The key is the service name the metrics carry. Each request is a POST to
  `{url}/telemetry/directives` (`path` overrides it), signed with
  `X-Telemetry-Timestamp` and
  `X-Telemetry-Signature: sha256=<hex HMAC-SHA256 of "<timestamp>.<body>">`.
  The app allows five minutes of clock skew.

- **In-process**, when `cboxdk/laravel-telemetry` with directives runs in
  this same app: a service with no target that is this app's
  (`telemetry.service.name`), or no service at all, goes straight into its
  directive store.

A service with neither is recorded as a failed run that says what to
configure. A refusal from the app (bad signature, too many directives) is
recorded the same way; the rotation moves on to its next pick.

## Requirements

A `cboxdk/laravel-telemetry` with directives in each monitored app (the
release after 3.x that added them), and the request-duration histogram
(`http_server_request_duration_seconds`, labelled `service_name` and
`http_route`) in the dashboard's metrics store for the rotation.
