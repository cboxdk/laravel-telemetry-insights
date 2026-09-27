<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Testing;

/**
 * Which insights screens the documentation shows.
 *
 * Same shape as laravel-telemetry-ui's manifest, deliberately: one list, a
 * capture test that writes `docs/screenshots/<key>.png`, and committed
 * PNGs because the docs site scrapes tagged releases and cannot run a
 * browser.
 *
 * Adding one:
 *
 *  1. an entry here, with the caption the docs will use;
 *  2. `![caption](screenshots/<key>.png)` in the page that describes it;
 *  3. `composer screenshots`
 *
 * Set `TELEMETRY_INSIGHTS_SCREENSHOT=<key>` to recapture one instead of all.
 */
final class ScreenshotManifest
{
    /**
     * @return array<string, array{url: string, caption: string, width: int, height: int, full: bool}>
     */
    public static function all(): array
    {
        return [
            'issues' => [
                'url' => '/telemetry-ui/p/issues',
                'caption' => 'Issues: one row per error fingerprint, with the status the team gave it — open, snoozed, resolved in a named release — rather than one row per occurrence.',
                'width' => 1600,
                'height' => 1000,
                'full' => false,
            ],
            'incidents' => [
                'url' => '/telemetry-ui/p/incidents',
                'caption' => 'Incidents: error groups that moved together, with the suspected cause and the evidence for it — a dependency that started failing, or a deploy the onset followed.',
                'width' => 1600,
                'height' => 1000,
                'full' => false,
            ],
        ];
    }
}
