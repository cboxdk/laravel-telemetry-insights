---
title: Testing
description: The trait and fake the package uses on itself, for your suite too
weight: 12
---

# Testing

## Stop notifications from going anywhere

```php
use Cbox\TelemetryInsights\Testing\InteractsWithInsights;

uses(InteractsWithInsights::class);

it('pages on-call when checkout starts failing', function (): void {
    $notifier = $this->fakeNotifications();

    // … make something break …

    $notifier->assertSentCount(1);
    $notifier->assertSent('Checkout error rate');
});
```

`fakeNotifications()` swaps the notifier in the container for one that
records. It returns the fake, so you can assert on it directly:

| | |
| --- | --- |
| `assertSent(string $needle)` | A notification title contained this |
| `assertSentCount(int $n)` | Exactly this many went out |
| `assertNothingSent()` | Nothing did |
| `titles()` | Every title, for a custom assertion |

The package's own suite uses this trait for every test that could notify —
if it were awkward, we would have noticed.

## Faking the telemetry behind it

Insights reads through the dashboard's drivers, which use Laravel's HTTP
client, so `Http::fake()` is all you need. The package's own tests build
realistic Loki and Tempo payloads rather than mocking the sources, so a
change in how a backend answers shows up as a failing test.
