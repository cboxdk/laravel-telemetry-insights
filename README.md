# Cbox Telemetry Insights

**The stateful half of observability for Laravel** — an issue ledger,
incident correlation, alerting and digests, on top of
[`cboxdk/laravel-telemetry-ui`](https://github.com/cboxdk/laravel-telemetry-ui).

The dashboard is a read-only window onto Tempo, Loki and Prometheus. It
queries your telemetry stores and writes nothing to them, which is a
checkable property rather than a slogan. This package adds the things that
need to *remember* something — and keeps them in four tables of its own, so
that property survives.

## The problem it solves

A cache node dies at 02:14. Your application does not report "the cache is
down". It reports:

```
RedisException: Connection refused                  ← session handler
TypeError: Argument must be of type array, null     ← a cached array came back empty
RuntimeException: Session store not set on request  ← middleware
ErrorException: Undefined array key "rate"          ← rate limiter
```

Four fingerprints, four places in the code, four alerts, none of which
mentions the cache. Whoever is on call spends twenty minutes deciding which
of four unrelated-looking bugs to chase.

They are one event, and this package says so:

```
Incident: redis cache-1:6379 — 4 error groups affected
100% of the affected groups called this cache, and the call
failed in 4 of 4 traces inspected.
```

It can do that because it knows what a Laravel app is: which spans are
outbound calls, which attributes identify an instance, and which of them was
failing.

## Highlights

- **Issue ledger** — every error group plus the decision your team made:
  open, resolved, ignored, snoozed, set from one artisan command or your
  own code. A resolved group that fires again is a **regression**, detected
  automatically and reopened.
- **Spike detection** — a known issue firing several times harder than in
  the window before, with guards so small numbers and brand-new issues do
  not masquerade as one.
- **Incident correlation** — errors that started together, folded into one
  event with a named cause, ranked evidence and a confidence you can check.
- **Alerting that means something** — rules on new issues and incidents, not
  only on numbers; with error rate, p95, throughput and any metric as the
  escape hatch. Cooldowns so one broken thing pages you once, and an
  unreachable backend that never reads as a breach.
- **Digests** — what is worth looking at this week, ranked, each finding
  carrying the route, statement or instance that identifies it. Including
  the queries that are individually fast but run a thousand times.
- **A brief for your assistant** — `--markdown` prints the findings as one
  self-contained document, ready to paste in with the repository open.
- **Slack, webhook or your own channel** — one method to implement, no SDK.
- **An API for your own screen** — read and change issues and incidents
  over HTTP, behind the dashboard's own gate.
- **Inert when idle** — boot registers class-strings only; one env var
  disables it entirely.

## Install

```bash
composer require cboxdk/laravel-telemetry-insights
php artisan migrate
```

PHP 8.3+, Laravel 12 or 13. No connections to configure — it reads through
the dashboard's.

```bash
php artisan telemetry-insights:scan --window=1h
php artisan telemetry-insights:digest --window=7d
```

The three jobs put themselves on your scheduler. See the
[quickstart](docs/quickstart.md).

## Does it need the dashboard UI?

It needs the dashboard's **read layer** — contracts, drivers, query IR — and
that is a hard dependency. It does not need the dashboard to be *served*: an
app running `TELEMETRY_UI_ENABLED=false` still gets the full scan,
correlation and alerting. Everything that knows the UI exists lives in one
folder behind a check, and an architecture test keeps it there. See
[architecture](docs/core-concepts/architecture.md).

## Documentation

Full documentation lives in [`docs/`](docs/index.md):

- [Quickstart](docs/quickstart.md) · [Installation](docs/getting-started/installation.md) ·
  [Requirements](docs/requirements.md)
- Core concepts: [architecture](docs/core-concepts/architecture.md) ·
  [issues](docs/core-concepts/issues.md) ·
  [incidents](docs/core-concepts/incidents.md) ·
  [alerting](docs/core-concepts/alerting.md) ·
  [digests](docs/core-concepts/digests.md)
- Cookbook: [notify Slack](docs/cookbook/slack.md) ·
  [hand findings to an assistant](docs/cookbook/assistant-brief.md)
- Extending: [notification channels](docs/extension-points/channels.md) ·
  [your own correlation](docs/extension-points/correlation.md) ·
  [HTTP API](docs/extension-points/http-api.md)
- [Configuration reference](docs/configuration/reference.md) ·
  [what this package stores](docs/security/data.md) ·
  [testing](docs/getting-started/testing.md)

## Development

```bash
composer check   # pint + phpstan (level max) + pest — must pass
composer qa      # the above, plus audit, licenses
```

## License

MIT — see [LICENSE.md](LICENSE.md).
