<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Notify;

use Cbox\TelemetryInsights\Contracts\NotifiesChannel;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Log;

/**
 * Writes the notification to the log. The default channel, so a fresh
 * install alerts somewhere rather than nowhere while you wire up Slack.
 */
final readonly class LogChannel implements NotifiesChannel
{
    public function __construct(private Config $config) {}

    public function send(Notification $notification): bool
    {
        $level = $this->config->get('telemetry-insights.notify.log.level', 'warning');
        $level = is_string($level) ? $level : 'warning';

        Log::log($level, 'telemetry-insights: '.$notification->title, [
            'body' => $notification->body,
            'severity' => $notification->severity->value,
            'facts' => $notification->facts,
            'url' => $notification->url,
        ]);

        return true;
    }
}
