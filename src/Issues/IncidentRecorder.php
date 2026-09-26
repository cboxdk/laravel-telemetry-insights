<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Issues;

use Cbox\TelemetryInsights\Correlate\Incident as CorrelatedIncident;
use Cbox\TelemetryInsights\Models\Incident;
use Illuminate\Support\Carbon;

/**
 * Persists a correlated burst so it keeps its identity between runs.
 *
 * Correlation is pure and re-runs from scratch every pass; this is what
 * makes the result stable enough to acknowledge. A burst that grows from
 * nine groups to fourteen updates the same row rather than filing a second
 * incident.
 */
class IncidentRecorder
{
    /**
     * @return array{incident: Incident, isNew: bool}
     */
    public function record(CorrelatedIncident $correlated): array
    {
        $existing = Incident::query()->firstWhere('signature', $correlated->signature);
        $incident = $existing instanceof Incident ? $existing : new Incident;
        $isNew = ! $existing instanceof Incident;

        $attributes = [
            'signature' => $correlated->signature,
            'title' => $correlated->title(),
            'onset_at' => Carbon::createFromTimestampMs(intdiv($correlated->onsetNano, 1_000_000)),
            'cause_kind' => $correlated->cause?->kind,
            'cause_label' => $correlated->cause?->label,
            'cause_evidence' => $correlated->cause?->evidence,
            'cause_confidence' => $correlated->cause?->confidence,
            'cause_trace_id' => $correlated->cause?->traceId,
            'occurrences' => $correlated->occurrences(),
            'group_count' => count($correlated->groups),
            'fingerprints' => $correlated->fingerprints(),
            'services' => $correlated->services(),
        ];

        if ($isNew) {
            $attributes['status'] = IncidentStatus::Open;
        }

        $incident->forceFill($attributes)->save();

        return ['incident' => $incident, 'isNew' => $isNew];
    }
}
