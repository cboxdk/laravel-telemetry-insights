# CLAUDE.md

Laravel package: the stateful add-on to `cboxdk/laravel-telemetry-ui`
(../laravel-telemetry-ui) — issue ledger, incident correlation, alerting and
digests. The dashboard reads; this remembers.

## Commands

- `composer check` — pint --test, phpstan (level max), pest. Must pass.
- `composer qa` — the above plus `composer audit --no-dev` and
  `license-check`.
- `composer sbom` — regenerate `sbom.json`; CI fails on drift.

## Architecture (src/)

- `Correlate/` — pure domain. Folds error groups that started together into
  an `Incident` and names the cause. No state, no persistence: it re-runs
  from scratch each pass and stays stable via `Incident::signature()`.
- `Issues/` — `IssueLedger` (new vs regression vs recurring) and
  `IncidentRecorder`. The only reason the package has a database.
- `Alerts/` — `RuleMeasurer` (rule → number, through the query IR),
  `AlertEvaluator` (measured / breached / notifiable as three decisions).
- `Digest/` — ranked `Finding`s plus the Markdown brief.
- `Notify/` — `Notification` VO, channels behind `NotifiesChannel`.
- `Scan/` — the pass that ties ledger + correlation + announcements together.
- `Ui/` — the ONLY place allowed to touch the dashboard's presentation
  layer. Everything here registers behind a check.
- `Support/Setting` — typed config reads; never cast `config()` inline.

## Hard rules

- **The import boundary is tested.** Nothing outside `src/Ui/` may import
  `Cbox\TelemetryUi\Panels`, `\Http` or `\Facades`. The one exception is
  `Http\Api\RequestScope`, the query layer's scope value object. This keeps
  the package portable if the dashboard's read layer is ever extracted.
- Boot hygiene: the service provider registers only — no I/O, no queries.
- PHPStan level max, `declare(strict_types=1)` everywhere, Pest 4.
  No `@phpstan-ignore`, no baseline, no casts to silence.
- Typed value objects and enums in the domain; arrays only at serialization
  boundaries.
- `final` by default; the openness ignore list lives in the arch test and
  every entry states why.
- Never write to the telemetry stores. State goes in this package's tables.
- Published docs name no competitors — describe the design in our own terms.
- Follow the conventions of ../laravel-telemetry.

## Testing notes

- `Http::fake()` MERGES stubs and the first match wins, so calling it twice
  does not replace the first. `fakeBackends()` in tests/Pest.php reads a
  mutable holder for exactly this reason — use it when a test needs the
  window to change.
- The error reader falls back to Tempo for browser exceptions, so a test
  must stub `tempo.test:3200/api/search*` or the read fails and correlation
  correctly reports nothing.

## Verifying against live telemetry

No testbench.yaml is committed (it would leak into the suite). To run the
commands against a real backend, write one temporarily:

    providers: [Cbox\Telemetry\TelemetryServiceProvider,
                Cbox\TelemetryUi\TelemetryUiServiceProvider,
                Cbox\TelemetryInsights\TelemetryInsightsServiceProvider]

then export DB_CONNECTION/DB_DATABASE and the three TELEMETRY_UI_*_URL vars
and run `vendor/bin/testbench migrate --force` followed by the command.
Delete the file afterwards.
