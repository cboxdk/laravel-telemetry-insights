<?php

declare(strict_types=1);

use Cbox\TelemetryInsights\Ui\Http\IncidentController;
use Cbox\TelemetryInsights\Ui\Http\IssueController;
use Illuminate\Support\Facades\Route;

/*
 * Mounted beside the dashboard's own API, under its path, gate and
 * throttle. These exist so a host can build the screen this package does
 * not ship — the pages it adds to the dashboard are read-only lists.
 */

Route::prefix('api/v2/insights')->name('telemetry-insights.api.')->group(static function (): void {
    Route::get('/issues', [IssueController::class, 'index'])->name('issues');
    Route::post('/issues/{fingerprint}', [IssueController::class, 'update'])
        ->where('fingerprint', '[A-Za-z0-9]+')
        ->name('issue.update');

    Route::get('/incidents', [IncidentController::class, 'index'])->name('incidents');
    Route::post('/incidents/{signature}', [IncidentController::class, 'update'])
        ->where('signature', '[A-Za-z0-9]+')
        ->name('incident.update');
});
