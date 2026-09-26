<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Testing;

use Cbox\TelemetryInsights\Notify\Notification;
use Cbox\TelemetryInsights\Notify\Notifier;
use PHPUnit\Framework\Assert;

/**
 * Records notifications instead of sending them.
 *
 * Swapped in by {@see InteractsWithInsights::fakeNotifications()}; the
 * package's own tests use it, which is the only way to know it is pleasant
 * to use.
 */
final class FakeNotifier extends Notifier
{
    /** @var list<array{notification: Notification, channels: list<string>|null}> */
    public array $sent = [];

    public function __construct()
    {
        // The real Notifier needs a container and config to resolve channels;
        // this one never resolves anything, so it needs neither.
    }

    /**
     * @param  list<string>|null  $channels
     * @return list<string>
     */
    public function send(Notification $notification, ?array $channels = null): array
    {
        $this->sent[] = ['notification' => $notification, 'channels' => $channels];

        return $channels ?? ['fake'];
    }

    public function assertNothingSent(): void
    {
        Assert::assertSame([], $this->titles(), 'Expected no notifications to be sent.');
    }

    public function assertSentCount(int $expected): void
    {
        Assert::assertCount($expected, $this->sent, 'Unexpected number of notifications sent.');
    }

    /** Asserts one notification's title contains the given text. */
    public function assertSent(string $needle): void
    {
        foreach ($this->sent as $entry) {
            if (str_contains($entry['notification']->title, $needle)) {
                Assert::assertTrue(true);

                return;
            }
        }

        Assert::fail(sprintf(
            'No notification title contained [%s]. Sent: %s',
            $needle,
            $this->titles() === [] ? '(none)' : implode(', ', $this->titles()),
        ));
    }

    /** @return list<string> */
    public function titles(): array
    {
        return array_map(static fn (array $e): string => $e['notification']->title, $this->sent);
    }
}
