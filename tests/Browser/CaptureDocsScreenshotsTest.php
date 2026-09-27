<?php

declare(strict_types=1);

use Cbox\TelemetryInsights\Testing\InsightsFixture;
use Cbox\TelemetryInsights\Testing\ScreenshotManifest;

/**
 * Captures the documentation screenshots into `docs/screenshots/`.
 *
 * Off unless asked for, because it writes files the repository tracks:
 *
 *   composer screenshots
 *   TELEMETRY_INSIGHTS_SCREENSHOT=issues composer screenshots
 *
 * The issues and incidents are the seeded fixture, so a shot taken on a
 * laptop matches one taken in CI. See {@see InsightsFixture}.
 */
it('captures the documentation screenshots', function (): void {
    $only = getenv('TELEMETRY_INSIGHTS_SCREENSHOT') ?: null;
    $target = dirname(__DIR__, 2).'/docs/screenshots';

    if (! is_dir($target)) {
        mkdir($target, 0o755, true);
    }

    $captured = [];

    foreach (ScreenshotManifest::all() as $key => $shot) {
        if ($only !== null && $only !== $key) {
            continue;
        }

        visit($shot['url'])
            ->resize($shot['width'], $shot['height'])
            ->assertDontSee('Loading dashboard')
            ->assertDontSee('Page not found')
            ->waitForEvent('networkidle')
            ->screenshot($shot['full'], $key);

        $written = dirname(__DIR__).'/Browser/Screenshots/'.$key.'.png';

        expect($written)->toBeFile();

        rename($written, $target.'/'.$key.'.png');
        $captured[] = $key;
    }

    expect($captured)->not->toBeEmpty();

    fwrite(STDERR, "\ncaptured: ".implode(', ', $captured)."\n");
})->skip(
    fn (): bool => getenv('TELEMETRY_INSIGHTS_CAPTURE_SCREENSHOTS') !== '1',
    'set TELEMETRY_INSIGHTS_CAPTURE_SCREENSHOTS=1 to rewrite the committed PNGs',
);
