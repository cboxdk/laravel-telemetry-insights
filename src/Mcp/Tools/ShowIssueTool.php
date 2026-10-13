<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Mcp\Tools;

use Cbox\TelemetryInsights\Issues\IssueStatus;
use Cbox\TelemetryInsights\Models\Issue;
use Cbox\TelemetryInsights\Models\IssueEvent;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Explore\ErrorExplorer;
use Cbox\TelemetryUi\Http\Api\RequestScope;
use Cbox\TelemetryUi\Support\ScopeLock;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchema as JsonSchemaFactory;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * One issue as a Markdown brief: what it is, where, since when, what the
 * team decided and who decided it — plus, for an error group, the last
 * day's occurrences, the users it reached and a trace to open.
 *
 * The occurrence numbers are read from the telemetry store, never from the
 * ledger, which keeps none. A store that will not answer costs those lines,
 * not the brief.
 */
#[IsReadOnly]
final class ShowIssueTool extends LedgerTool
{
    protected string $name = 'show_issue';

    protected string $description = 'Show one issue from the ledger as Markdown: fingerprint, kind, status, first/last seen, the latest trace id, occurrences and affected users over the last 24h (error groups), and the history of decisions (who resolved/ignored/reopened it, via which channel, with comments). Accepts a unique fingerprint prefix.';

    /** Occurrences read for the 24h numbers; beyond this the count says "+". */
    private const SAMPLE = 500;

    /** History entries shown, newest last. */
    private const HISTORY = 10;

    public function __construct(ScopeLock $lock, private readonly ErrorExplorer $errors)
    {
        parent::__construct($lock);
    }

    /**
     * @return array<string, JsonSchemaFactory>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'fingerprint' => JsonSchemaFactory::string()->description('The issue fingerprint, or a unique prefix of it')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $issue = $this->find((string) $this->string($request, 'fingerprint'));

        if (! $issue instanceof Issue) {
            return Response::error('No issue matches that fingerprint (or the prefix is ambiguous). Use list_issues to find one.');
        }

        return Response::text($this->markdown($issue));
    }

    private function markdown(Issue $issue): string
    {
        $status = $issue->effectiveStatus();
        $kind = $issue->kind();

        $facts = [
            'Fingerprint' => '`'.$issue->fingerprint.'`',
            'Kind' => $kind->label(),
            'Status' => $status->label()
                .($status === IssueStatus::Snoozed ? ' until '.self::time($issue->snoozed_until) : '')
                .($status === IssueStatus::Archived
                    ? ' (reopens above '.$issue->escalation_spike_limit.' an hour sustained, or '.$issue->escalation_burst_limit.' in one hour)'
                    : '')
                .($issue->assignee !== null ? ' · assigned to '.$issue->assignee : ''),
            'Service' => implode(' · ', array_filter([$issue->service ?? '', $issue->environment ?? ''])),
            'Task' => $issue->subject !== null ? '`'.$issue->subject.'`' : '',
            'First seen' => (string) self::time($issue->first_recorded_at),
            'Last seen' => (string) self::time($issue->last_seen_at),
            'Resolved' => $issue->resolved_at !== null
                ? self::time($issue->resolved_at)
                    .($issue->resolved_by !== null ? ' by '.$issue->resolved_by : '')
                    .($issue->resolved_in_release !== null ? ' in '.$issue->resolved_in_release : '')
                : '',
        ];

        $traceId = $issue->last_trace_id;

        if (! $kind->isScheduled()) {
            $live = $this->occurrences($issue);
            $traceId = $live['traceId'] ?? $traceId;

            $facts['Occurrences (24h)'] = $live === null ? 'unavailable (the store did not answer)' : $live['count'];
            $facts['Affected users (24h)'] = $live === null ? '' : $live['users'];
        }

        $facts['Latest trace'] = $traceId !== null ? '`'.$traceId.'`' : '';

        $lines = ['# '.$issue->title(), ''];

        foreach ($facts as $label => $value) {
            if ($value !== '') {
                $lines[] = '- **'.$label.'**: '.$value;
            }
        }

        $message = trim((string) ($issue->message ?? ''));

        if ($message !== '') {
            $lines[] = '';
            $lines[] = '> '.str_replace("\n", "\n> ", $message);
        }

        $history = $this->history($issue);

        if ($history !== []) {
            $lines[] = '';
            $lines[] = '## History';
            $lines[] = '';
            array_push($lines, ...$history);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @return list<string>
     */
    private function history(Issue $issue): array
    {
        $events = $issue->events()->reorder()->orderByDesc('id')->limit(self::HISTORY)->get()->reverse();

        $lines = [];

        foreach ($events as $event) {
            if (! $event instanceof IssueEvent) {
                continue;
            }

            $comment = $event->comment !== null ? ' — "'.str_replace("\n", ' ', $event->comment).'"' : '';
            $lines[] = '- '.self::time($event->created_at).' '.$event->describe().$comment;
        }

        return $lines;
    }

    /**
     * The last day of this error group, read live: how often, how many
     * distinct users, and the newest trace.
     *
     * Read through the error explorer narrowed to the issue's service and
     * exception class, then matched on the fingerprint here — the scope
     * object has no fingerprint filter of its own.
     *
     * @return array{count: string, users: string, traceId: string|null}|null
     */
    private function occurrences(Issue $issue): ?array
    {
        $scope = new RequestScope(
            period: '24h',
            service: $issue->service ?? '',
            params: $issue->type !== null ? ['q' => $issue->type] : [],
        );

        try {
            $occurrences = $this->errors->occurrences($scope, self::SAMPLE);
        } catch (SourceException) {
            return null;
        }

        $count = 0;
        $users = [];
        $newest = 0;
        $traceId = null;

        foreach ($occurrences as $occurrence) {
            if ($occurrence['group'] !== $issue->fingerprint) {
                continue;
            }

            $count++;

            if ($occurrence['user'] !== '') {
                $users[$occurrence['user']] = true;
            }

            if ($occurrence['nano'] >= $newest && $occurrence['traceId'] !== null) {
                $newest = $occurrence['nano'];
                $traceId = $occurrence['traceId'];
            }
        }

        $truncated = count($occurrences) >= self::SAMPLE;

        return [
            'count' => $count.($truncated ? '+' : ''),
            'users' => $users === [] ? '' : count($users).($truncated ? '+' : ''),
            'traceId' => $traceId,
        ];
    }
}
