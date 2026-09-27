<?php

declare(strict_types=1);

/**
 * The two screens this package adds, rendered in a browser.
 *
 * Insights extends someone else's dashboard, which is exactly the
 * arrangement where a break goes unnoticed: this package's tests assert on
 * its own models and controllers, the dashboard's tests assert on its own
 * panels, and the seam between them — a page registered here, rendered
 * there, fed by an API contract owned there — was covered by neither.
 */
it('renders the insights screens without console errors', function (string $page): void {
    visit("/telemetry-ui/p/{$page}")
        // The shell ships a loading state and the SPA answers an unknown
        // route with its own "Page not found", both of which render
        // perfectly cleanly — so without these two the assertion below
        // passes against a page that never appeared.
        ->assertDontSee('Loading dashboard')
        ->assertDontSee('Page not found')
        ->assertNoJavascriptErrors()
        ->assertNoConsoleLogs();
})->with(['issues', 'incidents']);
