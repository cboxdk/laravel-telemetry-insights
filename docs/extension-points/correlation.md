---
title: Your own correlation
description: Replace the heuristics without touching storage, alerting or the UI
weight: 42
---

# Your own correlation

Correlation is a single contract:

```php
namespace Cbox\TelemetryInsights\Contracts;

interface CorrelatesIncidents
{
    /** @return list<\Cbox\TelemetryInsights\Correlate\Incident> */
    public function correlate(RequestScope $scope): array;
}
```

Bind your own and everything above it — persistence, announcements, the
digest, the dashboard page — keeps working:

```php
$this->app->bind(
    \Cbox\TelemetryInsights\Contracts\CorrelatesIncidents::class,
    \App\Telemetry\OurCorrelator::class,
);
```

## Reusing the parts

You rarely need to start over. The pieces are separately useful:

- `TraceDependencies::of($trace)` returns every downstream in a trace with
  whether the call to it failed — the dependency identity logic, without
  the clustering.
- `Incident::signature()` gives a burst a stable id across passes.
- The tuning knobs under `telemetry-insights.correlation` cover burst
  window, minimum size, shared ratio and probe budget; a different threshold
  is config, not code.

## Adding to a digest

`DigestBuilder` is not final. Subclass it, call `parent::build()`, and
append your own `Finding`s before returning — findings are ranked by kind
and magnitude, so a new kind slots into the order without changing anything
else.
