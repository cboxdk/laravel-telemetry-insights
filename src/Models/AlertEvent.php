<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Models;

use Cbox\TelemetryInsights\Support\Tables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One firing of a rule — the record that something crossed the line, and
 * what it was.
 *
 * @property int $id
 * @property int $alert_rule_id
 * @property float|null $value
 * @property float|null $threshold
 * @property string $summary
 * @property array<string, mixed>|null $context
 * @property Carbon $fired_at
 * @property Carbon|null $resolved_at
 */
class AlertEvent extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return Tables::alertEvents();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'float',
            'threshold' => 'float',
            'context' => 'array',
            'fired_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<AlertRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class, 'alert_rule_id');
    }
}
