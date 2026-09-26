<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Correlate;

use Cbox\TelemetryInsights\Contracts\CorrelatesIncidents;
use Cbox\TelemetryUi\Analysis\SignalContext;
use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Explore\ErrorExplorer;
use Cbox\TelemetryUi\Http\Api\RequestScope;
use Cbox\TelemetryUi\Queries\Results\Trace;
use Cbox\TelemetryUi\Support\Annotations;
use Cbox\TelemetryUi\Support\ScopeLabels;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Folds a window's error groups into incidents.
 *
 * The premise: errors that start together usually broke together. A cache
 * node dying shows up as a `ConnectionException` in the session handler, a
 * `RedisException` in a rate limiter and a `TypeError` where some cached
 * array came back null — three unrelated fingerprints, three places in the
 * code, one cause. Grouping them by *when* they started and then asking what
 * they have in common recovers the one thing worth waking up for.
 *
 * Everything here is read-only and fails open: a cause we cannot establish
 * leaves the incident without one rather than failing the run, because an
 * incident with no cause is still worth seeing.
 */
final readonly class IncidentCorrelator implements CorrelatesIncidents
{
    public function __construct(
        private ErrorExplorer $errors,
        private ConnectionManager $connections,
        private TraceDependencies $dependencies,
        private Annotations $annotations,
        private SignalContext $signals,
        private Config $config,
    ) {}

    /**
     * @return list<Incident>
     */
    public function correlate(RequestScope $scope): array
    {
        $groups = $this->groups($scope);

        if ($groups === []) {
            return [];
        }

        $incidents = [];

        foreach ($this->bursts($groups) as $burst) {
            $onset = $burst[0]->onsetNano;
            $cause = $this->attribute($burst, $scope, $onset);

            $incidents[] = new Incident(
                signature: Incident::signature($onset, $burst, $cause),
                onsetNano: $onset,
                groups: $burst,
                cause: $cause,
            );
        }

        return $incidents;
    }

    /**
     * The window's error groups, each with when it first fired and one trace
     * to inspect.
     *
     * @return list<AffectedGroup>
     */
    private function groups(RequestScope $scope): array
    {
        try {
            $occurrences = $this->errors->occurrences($scope, $this->int('sample_limit', 500));
        } catch (SourceException) {
            return [];
        }

        /** @var array<string, AffectedGroup> $groups */
        $groups = [];

        foreach ($occurrences as $occurrence) {
            $fingerprint = $occurrence['group'];
            $existing = $groups[$fingerprint] ?? null;

            if ($existing === null) {
                $groups[$fingerprint] = new AffectedGroup(
                    fingerprint: $fingerprint,
                    type: $occurrence['type'],
                    message: $occurrence['message'],
                    onsetNano: $occurrence['nano'],
                    count: 1,
                    service: $occurrence['service'],
                    sampleTraceId: $occurrence['traceId'],
                );

                continue;
            }

            $groups[$fingerprint] = new AffectedGroup(
                fingerprint: $fingerprint,
                type: $existing->type,
                message: $existing->message,
                // Onset is the earliest we saw it, so a burst is dated by
                // when it began and not by its latest noise.
                onsetNano: min($existing->onsetNano, $occurrence['nano']),
                count: $existing->count + 1,
                service: $existing->service,
                sampleTraceId: $existing->sampleTraceId ?? $occurrence['traceId'],
            );
        }

        $ordered = array_values($groups);
        usort($ordered, static fn (AffectedGroup $a, AffectedGroup $b): int => $a->onsetNano <=> $b->onsetNano);

        return $ordered;
    }

    /**
     * Split groups into bursts: runs of groups that all started within the
     * same short window. A lone group is an error, not an incident, so only
     * runs of at least `min_groups` survive.
     *
     * @param  list<AffectedGroup>  $groups  ordered by onset
     * @return list<list<AffectedGroup>>
     */
    private function bursts(array $groups): array
    {
        $window = $this->int('burst_seconds', 120) * 1_000_000_000;
        $minimum = max(2, $this->int('min_groups', 3));

        $bursts = [];
        /** @var list<AffectedGroup> $current */
        $current = [];

        foreach ($groups as $group) {
            if ($current !== [] && $group->onsetNano - $current[0]->onsetNano > $window) {
                if (count($current) >= $minimum) {
                    $bursts[] = $current;
                }

                $current = [];
            }

            $current[] = $group;
        }

        if (count($current) >= $minimum) {
            $bursts[] = $current;
        }

        return $bursts;
    }

    /**
     * Ask each explanation in turn and keep the one that explains most: a
     * failing shared dependency beats a deploy, which beats host pressure.
     *
     * @param  list<AffectedGroup>  $burst
     */
    private function attribute(array $burst, RequestScope $scope, int $onsetNano): ?SuspectedCause
    {
        $best = null;

        foreach ([$this->sharedDependency($burst, $onsetNano), $this->recentChange($scope, $onsetNano), $this->hostPressure($scope, $onsetNano)] as $candidate) {
            if ($candidate !== null && $candidate->beats($best)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * A downstream that most of the burst touched and that was itself
     * failing. This is the one that actually explains the Redis case: the
     * fingerprints have nothing in common, but their traces all end at the
     * same dead cache node.
     *
     * @param  list<AffectedGroup>  $burst
     */
    private function sharedDependency(array $burst, int $onsetNano): ?SuspectedCause
    {
        $probes = array_slice($burst, 0, $this->int('max_trace_probes', 20));

        /** @var array<string, array{signature: DependencySignature, groups: int, failed: int, traceId: string}> $tally */
        $tally = [];
        $inspected = 0;

        foreach ($probes as $group) {
            $trace = $this->trace($group->sampleTraceId);

            if ($trace === null) {
                continue;
            }

            $inspected++;

            foreach ($this->dependencies->of($trace) as $key => $dependency) {
                $tally[$key] ??= [
                    'signature' => $dependency['signature'],
                    'groups' => 0,
                    'failed' => 0,
                    'traceId' => $trace->traceId,
                ];
                $tally[$key]['groups']++;

                if ($dependency['failed']) {
                    $tally[$key]['failed']++;
                }
            }
        }

        if ($inspected < 2) {
            return null;
        }

        $ratio = $this->float('shared_ratio', 0.6);
        $best = null;
        $bestShare = 0.0;

        foreach ($tally as $entry) {
            // A dependency everything touches but that never fails is just
            // the database — shared, not guilty.
            if ($entry['failed'] === 0) {
                continue;
            }

            $share = $entry['groups'] / $inspected;

            if ($share >= $ratio && $share > $bestShare) {
                $best = $entry;
                $bestShare = $share;
            }
        }

        if ($best === null) {
            return null;
        }

        $percent = (int) round($bestShare * 100);

        $evidence = $percent.'% of the affected groups called this '
            .$best['signature']->kind->label().', and the call failed in '
            .$best['failed'].' of '.$inspected.' traces inspected.';

        // Ask the dependency's own exporter what it was doing. "Your calls
        // failed" is where most tools stop; "the node was at 98% of its
        // memory limit, evicting 4.2k keys a second" is what ends the
        // incident.
        $health = $this->dependencyHealth($best['signature']->target, $onsetNano);

        return new SuspectedCause(
            kind: CauseKind::Dependency,
            label: $best['signature']->label(),
            evidence: $health === null ? $evidence : $evidence.' '.$health,
            // Its own exporter agreeing that it was unhealthy is the
            // strongest corroboration available.
            confidence: $health !== null || $bestShare >= 0.9 ? Confidence::High : Confidence::Medium,
            traceId: $best['traceId'],
        );
    }

    /**
     * A deploy or other annotated change shortly before the burst. Weaker
     * than a failing dependency — plenty of deploys are innocent — but the
     * first thing anyone asks.
     */
    private function recentChange(RequestScope $scope, int $onsetNano): ?SuspectedCause
    {
        $onsetMs = intdiv($onsetNano, 1_000_000);
        $windowMs = $this->int('change_window_minutes', 120) * 60_000;

        try {
            $annotations = $this->annotations->lookback($scope->logQuery());
        } catch (SourceException) {
            return null;
        }

        foreach ($annotations as $annotation) {
            if ($annotation->timestampMs > $onsetMs) {
                continue;
            }

            $gap = $onsetMs - $annotation->timestampMs;

            if ($gap > $windowMs) {
                return null;
            }

            $minutes = max(1, intdiv((int) $gap, 60_000));

            return new SuspectedCause(
                kind: CauseKind::Deploy,
                label: $annotation->label,
                evidence: 'A '.$annotation->kind.' was recorded '.$minutes.' minute'
                    .($minutes === 1 ? '' : 's').' before the first error.',
                confidence: $gap <= 15 * 60_000 ? Confidence::High : Confidence::Medium,
                traceId: $annotation->traceId,
            );
        }

        return null;
    }

    /**
     * A host or runtime signal out of its normal band when the burst started.
     * A symptom rather than a cause, so it only wins when nothing else
     * explains the burst.
     */
    private function hostPressure(RequestScope $scope, int $onsetNano): ?SuspectedCause
    {
        $onset = (int) ($onsetNano / 1_000_000_000);
        $pad = $this->int('signal_pad_seconds', 300);

        try {
            $summaries = $this->signals->for(
                self::metricLabels($scope),
                new DateTimeImmutable('@'.($onset - $pad)),
                new DateTimeImmutable('@'.($onset + $pad)),
            );
        } catch (SourceException) {
            return null;
        }

        foreach ($summaries as $summary) {
            if (! $summary->isOutlier()) {
                continue;
            }

            return new SuspectedCause(
                kind: CauseKind::Host,
                label: $summary->label,
                evidence: $summary->label.' was '.round($summary->current, 2)
                    .' around the first error, against a usual '.round($summary->baseline ?? 0.0, 2).'.',
                confidence: Confidence::Medium,
            );
        }

        return null;
    }

    /**
     * What the dependency's own exporter says about it around the onset,
     * when discovery has tied one to this address. Only signals materially
     * outside their normal band are reported — a cache at its usual memory
     * is not evidence of anything.
     */
    private function dependencyHealth(string $target, int $onsetNano): ?string
    {
        if ($target === '') {
            return null;
        }

        $onset = intdiv($onsetNano, 1_000_000_000);
        $pad = $this->int('signal_pad_seconds', 300);

        try {
            $summaries = $this->signals->discovered(
                [$target],
                new DateTimeImmutable('@'.($onset - $pad)),
                new DateTimeImmutable('@'.($onset + $pad)),
            );
        } catch (SourceException) {
            return null;
        }

        $unusual = [];

        foreach ($summaries as $summary) {
            if ($summary->isOutlier()) {
                $unusual[] = strtolower($summary->label).' was '.round($summary->current, 2)
                    .' against a usual '.round($summary->baseline ?? 0.0, 2);
            }
        }

        if ($unusual === []) {
            return null;
        }

        return 'Its own exporter agrees: '.implode(', ', array_slice($unusual, 0, 3)).'.';
    }

    /**
     * The scope as Prometheus labels, for the signal lookup. Only what the
     * scope actually pins — an unset service must not become `service_name=""`.
     *
     * The dimension keys are `service` / `environment`, not the attribute
     * names: ScopeLabels throws on an unknown dimension, and because both
     * calls sit behind a non-empty check, passing the wrong ones only blew
     * up for installs that had actually scoped their scan.
     *
     * @return array<string, string>
     */
    private static function metricLabels(RequestScope $scope): array
    {
        $labels = [];

        if ($scope->service !== '') {
            $labels[ScopeLabels::metrics('service')] = $scope->service;
        }

        if ($scope->environment !== '') {
            $labels[ScopeLabels::metrics('environment')] = $scope->environment;
        }

        return $labels;
    }

    private function trace(?string $traceId): ?Trace
    {
        if ($traceId === null || $traceId === '') {
            return null;
        }

        try {
            return $this->connections->traces()->trace($traceId);
        } catch (SourceException) {
            return null;
        }
    }

    private function int(string $key, int $default): int
    {
        $value = $this->config->get('telemetry-insights.correlation.'.$key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    private function float(string $key, float $default): float
    {
        $value = $this->config->get('telemetry-insights.correlation.'.$key, $default);

        return is_numeric($value) ? (float) $value : $default;
    }
}
