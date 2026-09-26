<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Notify;

use Cbox\TelemetryInsights\Contracts\NotifiesChannel;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;

/**
 * Fans a notification out to the configured channels.
 *
 * Delivery is best-effort by design: a finding that reached three of four
 * channels still reached someone, and an outage in your pager must not take
 * down the job that noticed the outage in your app.
 */
class Notifier
{
    public function __construct(
        private readonly Container $container,
        private readonly Config $config,
    ) {}

    /**
     * @param  list<string>|null  $channels  channel names, or null for the configured default set
     * @return list<string> the channels that accepted it
     */
    public function send(Notification $notification, ?array $channels = null): array
    {
        $delivered = [];

        foreach ($channels ?? $this->defaults() as $name) {
            $channel = $this->resolve($name);

            if ($channel === null) {
                Log::warning('telemetry-insights: unknown notification channel.', ['channel' => $name]);

                continue;
            }

            if ($channel->send($notification)) {
                $delivered[] = $name;
            }
        }

        return $delivered;
    }

    private function resolve(string $name): ?NotifiesChannel
    {
        $class = $this->config->get('telemetry-insights.notify.channels.'.$name);

        if (! is_string($class) || ! is_a($class, NotifiesChannel::class, true)) {
            return null;
        }

        $channel = $this->container->make($class);

        return $channel instanceof NotifiesChannel ? $channel : null;
    }

    /**
     * @return list<string>
     */
    private function defaults(): array
    {
        $default = $this->config->get('telemetry-insights.notify.default', ['log']);

        if (is_string($default)) {
            return [$default];
        }

        if (! is_array($default)) {
            return ['log'];
        }

        return array_values(array_filter($default, static fn (mixed $v): bool => is_string($v) && $v !== ''));
    }
}
