---
title: Notification channels
description: Deliver findings anywhere by implementing one method
weight: 41
---

# Notification channels

```php
namespace App\Telemetry;

use Cbox\TelemetryInsights\Contracts\NotifiesChannel;
use Cbox\TelemetryInsights\Notify\Notification;

final readonly class PagerChannel implements NotifiesChannel
{
    public function send(Notification $notification): bool
    {
        // … deliver it …

        return true;
    }
}
```

Register it by name:

```php
// config/telemetry-insights.php
'notify' => [
    'default' => ['slack', 'pager'],
    'channels' => [
        'log' => \Cbox\TelemetryInsights\Notify\LogChannel::class,
        'slack' => \Cbox\TelemetryInsights\Notify\SlackChannel::class,
        'webhook' => \Cbox\TelemetryInsights\Notify\WebhookChannel::class,
        'pager' => \App\Telemetry\PagerChannel::class,
    ],
],
```

Channels are resolved from the container, so constructor injection works.

## The contract

**A channel must not throw.** Delivery is best-effort: a finding that
reached three of four channels still reached someone, and a channel that
raised an exception would take down the pass that produced the finding.
Return `false` instead, and log if it is worth knowing about.

## What a notification carries

| | |
| --- | --- |
| `title`, `body` | The prose |
| `severity` | Info, Warning or Critical — with a colour and an emoji |
| `facts` | Short label/value pairs: the numbers |
| `url` | Where to go, when there is somewhere |
| `brief` | The self-contained Markdown, on digests |

`toText()` renders the whole thing as plain text for channels with no
formatting at all.
