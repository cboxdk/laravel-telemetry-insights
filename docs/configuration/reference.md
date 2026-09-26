---
title: Reference
description: Every configuration key, its default and what it changes
weight: 51
---

# Configuration reference

`config/telemetry-insights.php`. Publish it with
`php artisan vendor:publish --tag=telemetry-insights-config`.

## Master switch

| Key | Env | Default | |
| --- | --- | --- | --- |
| `enabled` | `TELEMETRY_INSIGHTS_ENABLED` | `true` | Off registers no commands, schedule or pages |

## Scope

| Key | Env | Default | |
| --- | --- | --- | --- |
| `scope.service` | `TELEMETRY_INSIGHTS_SERVICE` | all | Limit the scheduled work to one service |
| `scope.environment` | `TELEMETRY_INSIGHTS_ENVIRONMENT` | all | Limit it to one environment |
| `scope.window` | `TELEMETRY_INSIGHTS_WINDOW` | `15m` | How far back each pass reads |

Keep the window comfortably longer than the scan interval, so a slow ingest
never drops an error between passes.

## Correlation

| Key | Env | Default | |
| --- | --- | --- | --- |
| `correlation.burst_seconds` | `TELEMETRY_INSIGHTS_BURST_SECONDS` | `120` | How close two groups must start to be one event |
| `correlation.min_groups` | `TELEMETRY_INSIGHTS_MIN_GROUPS` | `3` | Below this a burst is not an incident |
| `correlation.shared_ratio` | `TELEMETRY_INSIGHTS_SHARED_RATIO` | `0.6` | Share of the burst that must touch the failing dependency |
| `correlation.max_trace_probes` | `TELEMETRY_INSIGHTS_MAX_PROBES` | `20` | Trace fetches per pass — this bounds the cost |
| `correlation.sample_limit` | `TELEMETRY_INSIGHTS_SAMPLE_LIMIT` | `500` | Occurrences read per pass |
| `correlation.change_window_minutes` | `TELEMETRY_INSIGHTS_CHANGE_WINDOW` | `120` | How far back to look for a deploy to blame |
| `correlation.signal_pad_seconds` | — | `300` | Padding around onset when reading host signals |

## Digest

| Key | Env | Default | |
| --- | --- | --- | --- |
| `digest.limit` | `TELEMETRY_INSIGHTS_DIGEST_LIMIT` | `5` | Findings per kind |
| `digest.slow_route_ms` | `TELEMETRY_INSIGHTS_SLOW_ROUTE_MS` | `1000` | p95 above this is a finding |
| `digest.slow_query_ms` | `TELEMETRY_INSIGHTS_SLOW_QUERY_MS` | `100` | Average above this is a slow query |
| `digest.repeated_query_calls` | `TELEMETRY_INSIGHTS_REPEATED_QUERY_CALLS` | `100` | Calls above this is a repeated query |

## Notifications

| Key | Env | Default | |
| --- | --- | --- | --- |
| `notify.default` | — | `['log']` | Channels used when a rule names none |
| `notify.channels` | — | log, slack, webhook | Name → class map |
| `notify.log.level` | `TELEMETRY_INSIGHTS_LOG_LEVEL` | `warning` | |
| `notify.slack.webhook_url` | `TELEMETRY_INSIGHTS_SLACK_WEBHOOK` | — | |
| `notify.webhook.url` | `TELEMETRY_INSIGHTS_WEBHOOK_URL` | — | |

## Announcements

| Key | Env | Default | |
| --- | --- | --- | --- |
| `announce.new_issues` | `TELEMETRY_INSIGHTS_ANNOUNCE_NEW` | `true` | |
| `announce.regressions` | `TELEMETRY_INSIGHTS_ANNOUNCE_REGRESSIONS` | `true` | |
| `announce.incidents` | `TELEMETRY_INSIGHTS_ANNOUNCE_INCIDENTS` | `true` | |

Off means recorded silently, not ignored.

## Schedule

| Key | Env | Default | |
| --- | --- | --- | --- |
| `schedule.scan` | `TELEMETRY_INSIGHTS_SCAN_CRON` | `*/5 * * * *` | |
| `schedule.alerts` | `TELEMETRY_INSIGHTS_ALERTS_CRON` | `*/5 * * * *` | |
| `schedule.digest` | `TELEMETRY_INSIGHTS_DIGEST_CRON` | `0 8 * * 1` | |

Set one to an empty string to schedule that job yourself.

## Tables

| Key | Default |
| --- | --- |
| `tables.issues` | `telemetry_issues` |
| `tables.incidents` | `telemetry_incidents` |
| `tables.alert_rules` | `telemetry_alert_rules` |
| `tables.alert_events` | `telemetry_alert_events` |

Renaming a table here is enough; the migration reads the same config.
