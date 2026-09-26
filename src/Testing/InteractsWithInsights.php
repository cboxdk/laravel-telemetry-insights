<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Testing;

use Cbox\TelemetryInsights\Notify\Notifier;

/**
 * What a host's test suite needs to exercise insights without sending
 * anything anywhere. The package's own suite uses this, which is the only
 * way to find out whether it is pleasant.
 */
trait InteractsWithInsights
{
    /**
     * Swap the notifier for one that records instead of delivering.
     */
    protected function fakeNotifications(): FakeNotifier
    {
        $fake = new FakeNotifier;

        $this->app?->instance(Notifier::class, $fake);

        return $fake;
    }
}
