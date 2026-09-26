---
title: Notify Slack
description: Send issues, incidents and alerts to a channel people read
weight: 31
---

# Notify Slack

Create an [incoming webhook](https://api.slack.com/messaging/webhooks) for
the channel you want, then:

```dotenv
TELEMETRY_INSIGHTS_SLACK_WEBHOOK=https://hooks.slack.com/services/…
```

```php
// config/telemetry-insights.php
'notify' => [
    'default' => ['slack'],
],
```

That is the whole integration — one HTTP POST is the entire protocol, so
there is no SDK to install.

Notifications render as Block Kit: a coloured rail by severity, the facts as
fields, and a button when the finding has somewhere to go.

## Per-rule channels

A rule's `channels` override the default set, so noisy rules can go
somewhere quieter:

```php
AlertRule::create([
    // …
    'channels' => ['log'],
]);
```

## What gets sent

| | |
| --- | --- |
| A new issue | Warning |
| A regression | Critical — it escaped a fix |
| A new incident | Critical |
| An alert firing | Warning |

Turn the first three off under `telemetry-insights.announce` to keep the
scan recording them silently.

## When delivery fails

Channels never throw. A dead webhook is logged and the run continues: an
outage in your pager must not take down the job that noticed the outage in
your app. The Slack webhook URL is a secret and is never written to the log,
even when Slack rejects the call.
