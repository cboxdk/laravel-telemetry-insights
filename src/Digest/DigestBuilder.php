<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Digest;

use Cbox\TelemetryInsights\Contracts\CorrelatesIncidents;
use Cbox\TelemetryInsights\Correlate\Incident;
use Cbox\TelemetryInsights\Issues\ChangeKind;
use Cbox\TelemetryInsights\Issues\IssueChange;
use Cbox\TelemetryInsights\Issues\IssueLedger;
use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Contracts\AggregatesSpans;
use Cbox\TelemetryUi\Http\Api\RequestScope;
use Cbox\TelemetryUi\Queries\Ir\SpanAggregation;
use Cbox\TelemetryUi\Queries\Ir\SpanSort;
use Cbox\TelemetryUi\Queries\Ir\TraceCondition;
use Cbox\TelemetryUi\Queries\Ir\TraceQuery;
use Cbox\TelemetryUi\Queries\Results\Sample;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Walks a window and collects what a developer would want to know about it.
 *
 * Every source fails open on its own: a store with no span aggregation
 * still gets you incidents and slow routes, and a Prometheus that is down
 * still gets you the issues. A partial digest beats no digest.
 */
class DigestBuilder
{
    public function __construct(
        private readonly CorrelatesIncidents $correlator,
        private readonly IssueLedger $ledger,
        private readonly ConnectionManager $connections,
        private readonly Config $config,
    ) {}

    public function build(RequestScope $scope): Digest
    {
        [$start, $end] = $scope->range();

        $findings = [
            ...$this->incidents($scope),
            ...$this->issues($scope),
            ...$this->slowRoutes($scope),
            ...$this->queries($scope, $start, $end),
        ];

        return Digest::of(
            DateTimeImmutable::createFromInterface($start),
            DateTimeImmutable::createFromInterface($end),
            $findings,
            $this->scopeLabel($scope),
        );
    }

    /**
     * @return list<Finding>
     */
    private function incidents(RequestScope $scope): array
    {
        return array_map(
            static fn (Incident $incident): Finding => new Finding(
                kind: FindingKind::Incident,
                title: $incident->title(),
                detail: $incident->cause?->evidence
                    ?? 'These error groups started within moments of each other; no shared cause was established.',
                facts: array_filter([
                    'Started' => date('Y-m-d H:i', intdiv($incident->onsetNano, 1_000_000_000)),
                    'Error groups' => (string) count($incident->groups),
                    'Occurrences' => (string) $incident->occurrences(),
                    'Services' => implode(', ', $incident->services()),
                    'Suspected cause' => $incident->cause?->label ?? '',
                    'Confidence' => $incident->cause?->confidence->value ?? '',
                    'Example trace' => $incident->cause?->traceId ?? '',
                ], static fn (string $v): bool => $v !== ''),
                magnitude: (float) $incident->occurrences(),
            ),
            $this->correlator->correlate($scope),
        );
    }

    /**
     * @return list<Finding>
     */
    private function issues(RequestScope $scope): array
    {
        $findings = [];

        foreach ($this->ledger->record($scope) as $change) {
            if (! $change->kind->isNews()) {
                continue;
            }

            $findings[] = $this->issueFinding($change);
        }

        return $findings;
    }

    private function issueFinding(IssueChange $change): Finding
    {
        $issue = $change->issue;
        $regression = $change->kind === ChangeKind::Regression;

        return new Finding(
            kind: $regression ? FindingKind::Regression : FindingKind::NewIssue,
            title: ($issue->type ?? 'Exception').($regression ? ' is back' : ' is new'),
            detail: (string) ($issue->message ?? ''),
            facts: array_filter([
                'Fingerprint' => $issue->fingerprint,
                'Service' => (string) ($issue->service ?? ''),
                'Occurrences in window' => (string) $change->count,
                'Resolved in' => (string) ($issue->resolved_in_release ?? ''),
            ], static fn (string $v): bool => $v !== ''),
            magnitude: (float) $change->count,
        );
    }

    /**
     * The routes carrying the worst p95. The common denominator a developer
     * asked for: which path, how slow, how often.
     *
     * @return list<Finding>
     */
    private function slowRoutes(RequestScope $scope): array
    {
        $threshold = (float) $this->config->get('telemetry-insights.digest.slow_route_ms', 1000);
        $limit = (int) $this->config->get('telemetry-insights.digest.limit', 5);

        try {
            $samples = $this->connections->metrics()->query(
                $scope->metricQuery('http_server_request_duration_seconds_bucket')
                    ->quantile(0.95, $scope->promDuration(), 'http_route')
                    ->times(1000),
            );
        } catch (SourceException) {
            return [];
        }

        $slow = array_values(array_filter(
            $samples,
            static fn (Sample $s): bool => $s->value > $threshold && ($s->labels['http_route'] ?? '') !== '',
        ));

        usort($slow, static fn (Sample $a, Sample $b): int => $b->value <=> $a->value);

        return array_map(
            static fn (Sample $s): Finding => new Finding(
                kind: FindingKind::SlowRoute,
                title: 'Slow route '.($s->labels['http_route'] ?? ''),
                detail: 'p95 is '.round($s->value).'ms over the window, above the '.round($threshold).'ms budget.',
                facts: [
                    'Route' => (string) ($s->labels['http_route'] ?? ''),
                    'p95' => round($s->value).'ms',
                ],
                magnitude: $s->value,
            ),
            array_slice($slow, 0, $limit),
        );
    }

    /**
     * The database work behind the slowness: the statements costing the most
     * total time, and the ones called absurdly often for one window (the
     * shape an N+1 makes).
     *
     * Needs a store that can aggregate spans server-side; a backend without
     * {@see AggregatesSpans} simply contributes nothing here.
     *
     * @return list<Finding>
     */
    private function queries(RequestScope $scope, \DateTimeInterface $start, \DateTimeInterface $end): array
    {
        try {
            $traces = $this->connections->traces();
        } catch (SourceException) {
            return [];
        }

        if (! $traces instanceof AggregatesSpans) {
            return [];
        }

        $limit = (int) $this->config->get('telemetry-insights.digest.limit', 5);

        try {
            $buckets = $traces->aggregateSpans(
                new SpanAggregation(
                    where: (new TraceQuery)->where(TraceCondition::nil('span.db.query.text')),
                    groupBy: 'span.db.query.text',
                    carry: ['span.db.system.name'],
                    limit: max($limit, 10),
                    sort: SpanSort::Total,
                ),
                $start,
                $end,
            );
        } catch (SourceException) {
            return [];
        }

        $slowMs = (float) $this->config->get('telemetry-insights.digest.slow_query_ms', 100);
        $repeatCalls = (int) $this->config->get('telemetry-insights.digest.repeated_query_calls', 100);

        $findings = [];

        foreach ($buckets as $bucket) {
            if ($bucket->key === '') {
                continue;
            }

            $facts = array_filter([
                'Statement' => $bucket->key,
                'System' => (string) ($bucket->attributes['db.system.name'] ?? ''),
                'Calls' => (string) $bucket->count,
                'Average' => round($bucket->avgMs, 1).'ms',
                'Total' => round($bucket->totalMs).'ms',
            ], static fn (string $v): bool => $v !== '');

            if ($bucket->avgMs >= $slowMs) {
                $findings[] = new Finding(
                    kind: FindingKind::SlowQuery,
                    title: 'Slow query averaging '.round($bucket->avgMs, 1).'ms',
                    detail: 'Called '.$bucket->count.' times for '.round($bucket->totalMs).'ms of database time in this window.',
                    facts: $facts,
                    magnitude: $bucket->totalMs,
                );

                continue;
            }

            // Fast but relentless: the signature of a query inside a loop.
            if ($bucket->count >= $repeatCalls) {
                $findings[] = new Finding(
                    kind: FindingKind::RepeatedQuery,
                    title: 'Query called '.$bucket->count.' times',
                    detail: 'Individually fast ('.round($bucket->avgMs, 1).'ms) but run '.$bucket->count
                        .' times, costing '.round($bucket->totalMs).'ms in total — the shape a query inside a loop makes.',
                    facts: $facts,
                    magnitude: (float) $bucket->count,
                );
            }
        }

        return array_slice($findings, 0, $limit);
    }

    private function scopeLabel(RequestScope $scope): string
    {
        $parts = array_filter([$scope->service, $scope->environment]);

        return $parts === [] ? 'all services' : implode(' · ', $parts);
    }
}
