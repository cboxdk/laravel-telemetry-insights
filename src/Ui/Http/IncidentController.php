<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Ui\Http;

use Cbox\TelemetryInsights\Issues\IncidentStatus;
use Cbox\TelemetryInsights\Models\Incident;
use Cbox\TelemetryUi\Http\Api\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Correlated incidents over HTTP. Acknowledging one is the whole point of
 * persisting them: it is how a team says "someone is on this" without
 * muting the errors underneath.
 */
final class IncidentController
{
    public function index(): JsonResponse
    {
        $incidents = Incident::query()
            ->orderByDesc('onset_at')
            ->limit(50)
            ->get();

        return new JsonResponse([
            'incidents' => $incidents->map(static fn (Incident $i): array => self::payload($i))->values()->all(),
        ]);
    }

    /**
     * `POST incidents/{signature}` with `{"action": "acknowledge"}`.
     */
    public function update(Request $request, string $signature): JsonResponse
    {
        if (! Gate::allows('manageTelemetryUi')) {
            return ApiError::forbidden('You are not authorized to change incidents.');
        }

        $incident = Incident::query()->firstWhere('signature', $signature);

        if (! $incident instanceof Incident) {
            return ApiError::notFound('No incident recorded under that signature.');
        }

        $action = $request->input('action');
        $by = $request->input('by');
        $by = is_string($by) && trim($by) !== '' ? trim($by) : null;

        $attributes = match ($action) {
            'acknowledge' => [
                'status' => IncidentStatus::Acknowledged,
                'acknowledged_at' => Carbon::now(),
                'acknowledged_by' => $by,
            ],
            'resolve' => ['status' => IncidentStatus::Resolved, 'resolved_at' => Carbon::now()],
            'reopen' => ['status' => IncidentStatus::Open, 'resolved_at' => null],
            default => null,
        };

        if ($attributes === null) {
            return ApiError::invalid('Unknown action. Use acknowledge, resolve or reopen.');
        }

        $incident->forceFill($attributes)->save();

        return new JsonResponse(['incident' => self::payload($incident)]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(Incident $incident): array
    {
        return [
            'signature' => $incident->signature,
            'status' => $incident->status->value,
            'title' => $incident->title,
            'onsetAt' => $incident->onset_at->toIso8601String(),
            'occurrences' => $incident->occurrences,
            'groupCount' => $incident->group_count,
            'fingerprints' => $incident->fingerprints,
            'services' => $incident->services,
            'cause' => $incident->cause_kind === null ? null : [
                'kind' => $incident->cause_kind->value,
                'label' => $incident->cause_label,
                'evidence' => $incident->cause_evidence,
                'confidence' => $incident->cause_confidence?->value,
                'traceId' => $incident->cause_trace_id,
            ],
            'acknowledgedAt' => $incident->acknowledged_at?->toIso8601String(),
            'acknowledgedBy' => $incident->acknowledged_by,
            'resolvedAt' => $incident->resolved_at?->toIso8601String(),
        ];
    }
}
