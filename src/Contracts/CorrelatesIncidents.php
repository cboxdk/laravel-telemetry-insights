<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Contracts;

use Cbox\TelemetryInsights\Correlate\Incident;
use Cbox\TelemetryInsights\Correlate\IncidentCorrelator;
use Cbox\TelemetryUi\Http\Api\RequestScope;

/**
 * Folds a window's error groups into incidents — bursts that started
 * together and share a cause.
 *
 * Bound to {@see IncidentCorrelator}.
 * Swap the binding to correlate differently (a model, your own heuristics)
 * without touching the storage, alerting or UI layers above it.
 */
interface CorrelatesIncidents
{
    /**
     * @return list<Incident>
     */
    public function correlate(RequestScope $scope): array;
}
