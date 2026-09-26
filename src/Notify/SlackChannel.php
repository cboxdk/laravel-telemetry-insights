<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Notify;

use Cbox\TelemetryInsights\Contracts\NotifiesChannel;
use Cbox\TelemetryInsights\Support\Setting;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Posts to a Slack incoming webhook as Block Kit.
 *
 * A webhook, not the Slack SDK: one HTTP POST is the whole protocol, and a
 * dependency that pulls in an API client to build a JSON array would earn
 * its place in nobody's vendor directory.
 */
final readonly class SlackChannel implements NotifiesChannel
{
    public function __construct(
        private HttpFactory $http,
        private Config $config,
        private Setting $settings,
    ) {}

    public function send(Notification $notification): bool
    {
        $webhook = $this->config->get('telemetry-insights.notify.slack.webhook_url');

        if (! is_string($webhook) || $webhook === '') {
            return false;
        }

        try {
            $response = $this->http
                ->timeout($this->settings->int('telemetry-insights.notify.slack.timeout', 5))
                ->post($webhook, $this->payload($notification));

            if ($response->successful()) {
                return true;
            }

            // Slack answers a bad webhook with a body worth reading, but the
            // URL itself is a secret — never log it.
            Log::warning('telemetry-insights: Slack rejected the notification.', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 200),
            ]);

            return false;
        } catch (Throwable $e) {
            Log::warning('telemetry-insights: Slack notification failed.', ['exception' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Notification $notification): array
    {
        $blocks = [
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => '*'.$notification->severity->emoji().' '.$notification->title.'*'."\n".$notification->body,
                ],
            ],
        ];

        if ($notification->facts !== []) {
            $fields = [];

            foreach (array_slice($notification->facts, 0, 10, true) as $label => $value) {
                $fields[] = ['type' => 'mrkdwn', 'text' => '*'.$label."*\n".$value];
            }

            $blocks[] = ['type' => 'section', 'fields' => $fields];
        }

        if ($notification->url !== null) {
            $blocks[] = [
                'type' => 'actions',
                'elements' => [[
                    'type' => 'button',
                    'text' => ['type' => 'plain_text', 'text' => 'Open in the dashboard'],
                    'url' => $notification->url,
                ]],
            ];
        }

        return [
            'text' => $notification->title,
            'attachments' => [[
                'color' => $notification->severity->color(),
                'blocks' => $blocks,
            ]],
        ];
    }
}
