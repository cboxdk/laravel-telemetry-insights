<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Models;

use Cbox\TelemetryInsights\Alerts\AlertType;
use Cbox\TelemetryInsights\Alerts\Comparator;
use Cbox\TelemetryInsights\Support\Tables;
use Cbox\TelemetryUi\Http\Api\RequestScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One thing worth being told about.
 *
 * @property int $id
 * @property string $name
 * @property AlertType $type
 * @property Comparator|null $comparator
 * @property float|null $threshold
 * @property int $window_minutes
 * @property int $cooldown_minutes
 * @property array{service?: string, environment?: string}|null $scope
 * @property string|null $metric
 * @property list<string>|null $channels
 * @property bool $enabled
 * @property Carbon|null $last_evaluated_at
 * @property Carbon|null $last_fired_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> query()
 */
class AlertRule extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return Tables::alertRules();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AlertType::class,
            'comparator' => Comparator::class,
            'threshold' => 'float',
            'window_minutes' => 'integer',
            'cooldown_minutes' => 'integer',
            'scope' => 'array',
            'channels' => 'array',
            'enabled' => 'boolean',
            'last_evaluated_at' => 'immutable_datetime',
            'last_fired_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<AlertEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(AlertEvent::class);
    }

    public function breached(float $value): bool
    {
        $threshold = $this->threshold;
        $comparator = $this->comparator;

        if ($threshold === null || $comparator === null) {
            return false;
        }

        return $comparator->breached($value, $threshold);
    }

    /**
     * Still inside the quiet period after the last firing. The point of a
     * cooldown is that one broken thing pages you once, not every minute
     * until you fix it.
     */
    public function inCooldown(): bool
    {
        $last = $this->last_fired_at;

        return $last !== null && $last->addMinutes($this->cooldown_minutes)->isFuture();
    }

    /** The slice of telemetry this rule reads, as the query layer wants it. */
    public function scope(): RequestScope
    {
        $scope = $this->scope ?? [];

        return new RequestScope(
            period: $this->window_minutes.'m',
            service: $scope['service'] ?? '',
            environment: $scope['environment'] ?? '',
        );
    }
}
