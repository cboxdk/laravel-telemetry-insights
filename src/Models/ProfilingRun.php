<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Models;

use Cbox\TelemetryInsights\Support\Tables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One directive this package asked a monitored app for — by the profiling
 * rotation or ad hoc — and whether it was placed.
 *
 * The rotation reads this to move on: a route profiled in the last
 * cooldown is skipped, so the next pass looks at the next-worst thing
 * rather than the same one forever.
 *
 * @property int $id
 * @property string $source
 * @property string|null $service
 * @property string $match
 * @property string|null $key
 * @property string $value
 * @property bool $profile
 * @property int $minutes
 * @property float|null $impact
 * @property string|null $reason
 * @property string|null $created_by
 * @property string|null $directive_id
 * @property string $status
 * @property string|null $error
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 */
class ProfilingRun extends Model
{
    public const UPDATED_AT = null;

    public const ROTATION = 'rotation';

    public const ADHOC = 'adhoc';

    public const PLACED = 'placed';

    public const FAILED = 'failed';

    protected $guarded = [];

    public function getTable(): string
    {
        return Tables::profilingRuns();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'profile' => 'boolean',
            'minutes' => 'integer',
            'impact' => 'float',
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function active(): bool
    {
        return $this->status === self::PLACED && $this->expires_at !== null && $this->expires_at->isFuture();
    }

    public function target(): string
    {
        return $this->key !== null ? $this->match.' '.$this->key.'='.$this->value : $this->match.' '.$this->value;
    }
}
