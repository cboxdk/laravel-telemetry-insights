<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Models;

use Cbox\TelemetryInsights\Issues\ActionSource;
use Cbox\TelemetryInsights\Issues\IssueAction;
use Cbox\TelemetryInsights\Support\Tables;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One decision in an issue's history: what was done, by whom, through
 * which door, and the comment that came with it.
 *
 * Append-only. Nothing in the package edits or deletes a row; deleting the
 * issue takes its history with it.
 *
 * @property int $id
 * @property int $issue_id
 * @property IssueAction $action
 * @property string|null $actor
 * @property ActionSource $source
 * @property string|null $comment
 * @property Carbon|null $created_at
 */
class IssueEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    public function getTable(): string
    {
        return Tables::issueEvents();
    }

    /**
     * @return BelongsTo<Issue, $this>
     */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => IssueAction::class,
            'source' => ActionSource::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    /** "Resolved via agent by claude@example.com" — one line for a history list. */
    public function describe(): string
    {
        $actor = $this->actor ?? '';

        return $this->action->label().' '.$this->source->label().($actor !== '' ? ' by '.$actor : '');
    }
}
