<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Notify;

use Cbox\TelemetryInsights\Contracts\NotifiesChannel;
use Cbox\TelemetryInsights\Support\Setting;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use Throwable;

/** POSTs the notification as JSON to a URL of the host's choosing. */
final readonly class WebhookChannel implements NotifiesChannel
{
    public function __construct(
        private HttpFactory $http,
        private Config $config,
        private Setting $settings,
    ) {}

    public function send(Notification $notification): bool
    {
        $url = $this->config->get('telemetry-insights.notify.webhook.url');

        if (! is_string($url) || $url === '') {
            return false;
        }

        try {
            return $this->http
                ->timeout($this->settings->int('telemetry-insights.notify.webhook.timeout', 5))
                ->post($url, [
                    'title' => $notification->title,
                    'body' => $notification->body,
                    'severity' => $notification->severity->value,
                    'facts' => $notification->facts,
                    'url' => $notification->url,
                    'brief' => $notification->brief,
                ])
                ->successful();
        } catch (Throwable $e) {
            Log::warning('telemetry-insights: webhook notification failed.', ['exception' => $e->getMessage()]);

            return false;
        }
    }
}
