<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Mcp\Tools;

use Cbox\TelemetryInsights\Issues\IssueKind;
use Cbox\TelemetryInsights\Issues\IssueStatus;
use Cbox\TelemetryInsights\Models\Issue;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\JsonSchema\JsonSchema as JsonSchemaFactory;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * The working list: open first, most recently seen first. One page at a
 * time, with the total and the offset of the next page, so an agent can
 * stop as soon as it has what it needs.
 */
#[IsReadOnly]
final class ListIssuesTool extends LedgerTool
{
    protected string $name = 'list_issues';

    protected string $description = 'List issues from the issue ledger (error groups and scheduled-task problems) with the decision the team made about each. Open first, then most recently seen. Filter by status, kind or service; page with offset/next_offset. Pass a fingerprint to show_issue for detail and history.';

    /** Long messages are cut here; show_issue has the whole thing. */
    private const MESSAGE_LENGTH = 160;

    /**
     * @return array<string, JsonSchemaFactory>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => JsonSchemaFactory::string()
                ->enum(array_map(static fn (IssueStatus $s): string => $s->value, IssueStatus::cases()))
                ->description('Only issues with this effective status (a lapsed snooze counts as open)'),
            'kind' => JsonSchemaFactory::string()
                ->enum(['scheduled', ...array_map(static fn (IssueKind $k): string => $k->value, IssueKind::cases())])
                ->description('Only this kind; "scheduled" means every scheduled-task kind'),
            'service' => JsonSchemaFactory::string()->description('Only this service.name'),
            'limit' => JsonSchemaFactory::integer()->description('Issues per page (max '.self::MAX_LIMIT.')')->default(20),
            'offset' => JsonSchemaFactory::integer()->description('Issues to skip (from next_offset)')->default(0),
        ];
    }

    public function handle(Request $request): Response
    {
        $limit = $this->int($request, 'limit', 20, 1, self::MAX_LIMIT);
        $offset = $this->int($request, 'offset', 0, 0, PHP_INT_MAX);

        $query = $this->visible(Issue::query());
        $this->status($query, $this->string($request, 'status'));
        $this->kind($query, $this->string($request, 'kind'));

        $service = $this->string($request, 'service');

        if ($service !== null) {
            $query->where('service', $service);
        }

        $total = (clone $query)->count();

        $issues = $query
            ->orderByRaw("case when status = 'open' then 0 else 1 end")
            ->orderByDesc('last_seen_at')
            ->orderBy('id')
            ->offset($offset)
            ->limit($limit)
            ->get();

        $rows = [];

        foreach ($issues as $issue) {
            if ($issue instanceof Issue) {
                $rows[] = $this->row($issue);
            }
        }

        return Response::json($this->compact([
            'total' => $total,
            'issues' => $rows,
            'next_offset' => $total > $offset + $limit ? $offset + $limit : null,
            'note' => $total === 0 ? 'No issues match. The ledger fills as telemetry-insights:scan runs.' : null,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Issue $issue): array
    {
        $message = (string) ($issue->message ?? '');

        return [
            'fingerprint' => $issue->fingerprint,
            'kind' => $issue->kind()->value,
            'title' => $issue->title(),
            'status' => $issue->effectiveStatus()->value,
            'service' => $issue->service,
            'environment' => $issue->environment,
            'message' => mb_strlen($message) > self::MESSAGE_LENGTH ? mb_substr($message, 0, self::MESSAGE_LENGTH - 1).'…' : $message,
            'last_seen' => self::time($issue->last_seen_at),
            'assignee' => $issue->assignee,
        ];
    }

    /**
     * Filter on the EFFECTIVE status, the one every other surface shows: a
     * snooze that has run out is open, and is not snoozed.
     *
     * @param  Builder<Issue>  $query
     */
    private function status(Builder $query, ?string $value): void
    {
        $status = $value === null ? null : IssueStatus::tryFrom($value);
        $now = Carbon::now();

        match ($status) {
            null => null,
            IssueStatus::Open => $query->where(static fn (Builder $q) => $q
                ->where('status', IssueStatus::Open->value)
                ->orWhere(static fn (Builder $q) => $q
                    ->where('status', IssueStatus::Snoozed->value)
                    ->where(static fn (Builder $q) => $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', $now)))),
            IssueStatus::Snoozed => $query->where('status', IssueStatus::Snoozed->value)->where('snoozed_until', '>', $now),
            default => $query->where('status', $status->value),
        };
    }

    /**
     * @param  Builder<Issue>  $query
     */
    private function kind(Builder $query, ?string $value): void
    {
        if ($value === null) {
            return;
        }

        if ($value === 'scheduled') {
            $query->whereIn('kind', array_map(static fn (IssueKind $k): string => $k->value, IssueKind::scheduled()));

            return;
        }

        $kind = IssueKind::tryFrom($value);

        // An unknown kind matches nothing rather than everything.
        $query->where('kind', $kind !== null ? $kind->value : "\0");
    }
}
