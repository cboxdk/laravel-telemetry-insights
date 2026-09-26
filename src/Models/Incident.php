<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Models;

use Cbox\TelemetryInsights\Correlate\CauseKind;
use Cbox\TelemetryInsights\Correlate\Confidence;
use Cbox\TelemetryInsights\Issues\IncidentStatus;
use Cbox\TelemetryInsights\Support\Tables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A correlated burst, persisted so it keeps its identity between runs and
 * someone can acknowledge it.
 *
 * The members are stored as fingerprints rather than as foreign keys: an
 * incident can name a group nobody has filed an {@see Issue} row for yet,
 * and correlation must not create rows as a side effect of reading.
 *
 * @property int $id
 * @property string $signature
 * @property IncidentStatus $status
 * @property string $title
 * @property Carbon $onset_at
 * @property CauseKind|null $cause_kind
 * @property string|null $cause_label
 * @property string|null $cause_evidence
 * @property Confidence|null $cause_confidence
 * @property string|null $cause_trace_id
 * @property int $occurrences
 * @property int $group_count
 * @property list<string> $fingerprints
 * @property list<string> $services
 * @property Carbon|null $acknowledged_at
 * @property string|null $acknowledged_by
 * @property Carbon|null $resolved_at
 * @property Carbon|null $notified_at
 */
class Incident extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return Tables::incidents();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => IncidentStatus::class,
            'cause_kind' => CauseKind::class,
            'cause_confidence' => Confidence::class,
            'onset_at' => 'immutable_datetime',
            'acknowledged_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'notified_at' => 'immutable_datetime',
            'fingerprints' => 'array',
            'services' => 'array',
            'occurrences' => 'integer',
            'group_count' => 'integer',
        ];
    }
}
