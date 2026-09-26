<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Contracts;

use Cbox\TelemetryInsights\Notify\Notification;

/**
 * Somewhere an insight can be delivered: Slack, mail, a webhook, your own
 * pager. Register implementations by name under
 * `telemetry-insights.notify.channels`.
 *
 * A channel must not throw: delivery is best-effort and a dead webhook must
 * never fail the run that produced the finding. Return false instead.
 */
interface NotifiesChannel
{
    /** Whether the notification was delivered. */
    public function send(Notification $notification): bool;
}
