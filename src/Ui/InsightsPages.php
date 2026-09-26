<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Ui;

use Cbox\TelemetryUi\Facades\TelemetryUi;
use Cbox\TelemetryUi\TelemetryUiManager;

/**
 * The soft half of the add-on.
 *
 * The package needs the dashboard's *read* layer — contracts, drivers, the
 * query IR — and that is a hard dependency. It does not need the dashboard's
 * *presentation* layer, and must keep working in an app that runs this
 * headless, so every page here is registered behind a check and nothing
 * outside this folder may import the panel or HTTP layers (there is an arch
 * test that says so).
 *
 * Registration is class-strings only, so an install that never opens the
 * dashboard pays nothing for these.
 */
final class InsightsPages
{
    public static function register(): void
    {
        if (! self::dashboardPresent()) {
            return;
        }

        TelemetryUi::page('incidents', 'Incidents', group: 'Insights', icon: 'alert');
        TelemetryUi::panel(Panels\IncidentsTable::class, page: 'incidents');

        TelemetryUi::page('issues', 'Issues', group: 'Insights', icon: 'bug');
        TelemetryUi::panel(Panels\IssuesTable::class, page: 'issues');
    }

    /**
     * Whether the dashboard's UI layer is installed and routed. Installed but
     * disabled is a perfectly normal headless setup, and registering pages
     * into a dashboard nobody serves would be harmless but pointless.
     */
    public static function dashboardPresent(): bool
    {
        return class_exists(TelemetryUiManager::class)
            && (bool) config('telemetry-ui.enabled', true);
    }
}
