<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Tests\Fixtures;

use Cbox\TelemetryInsights\Testing\InteractsWithInsights;
use Orchestra\Testbench\TestCase;

/**
 * A composition site for the package's testing trait, so the analyser sees
 * it in use the way a host's suite would use it. Never run as a test.
 */
final class TraitCompositionFixture extends TestCase
{
    use InteractsWithInsights;

    public function exercise(): void
    {
        $notifier = $this->fakeNotifications();
        $notifier->assertNothingSent();
    }
}
