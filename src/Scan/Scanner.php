<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Scan;

use Cbox\TelemetryInsights\Alerts\AlertEvaluator;
use Cbox\TelemetryInsights\Alerts\AlertType;
use Cbox\TelemetryInsights\Contracts\CorrelatesIncidents;
use Cbox\TelemetryInsights\Correlate\Incident as CorrelatedIncident;
use Cbox\TelemetryInsights\Issues\ChangeKind;
use Cbox\TelemetryInsights\Issues\IncidentRecorder;
use Cbox\TelemetryInsights\Issues\IssueChange;
use Cbox\TelemetryInsights\Issues\IssueLedger;
use Cbox\TelemetryInsights\Issues\Spike;
use Cbox\TelemetryInsights\Issues\SpikeDetector;
use Cbox\TelemetryInsights\Models\AlertRule;
use Cbox\TelemetryInsights\Notify\Notification;
use Cbox\TelemetryInsights\Notify\Notifier;
use Cbox\TelemetryInsights\Notify\Severity;
use Cbox\TelemetryUi\Http\Api\RequestScope;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Carbon;

/**
 * One pass over a window: fold the errors into the ledger, correlate what
 * burst together, persist it, and tell someone about the parts that are
 * news.
 *
 * "News" is doing the real work here. A window contains hundreds of
 * occurrences and almost all of them are the same things that were there an
 * hour ago; a monitor that announces all of them gets muted within a week.
 * Only a fingerprint nobody has seen, one that came back after being fixed,
 * and a newly correlated incident get through.
 */
class Scanner
{
    /** @var list<AlertRule> Resolved once per pass, used twice. */
    private array $spikeRules = [];

    public function __construct(
        private readonly IssueLedger $ledger,
        private readonly CorrelatesIncidents $correlator,
        private readonly IncidentRecorder $recorder,
        private readonly Notifier $notifier,
        private readonly SpikeDetector $spikes,
        private readonly AlertEvaluator $alerts,
        private readonly Config $config,
    ) {}

    public function scan(RequestScope $scope): ScanResult
    {
        $changes = $this->ledger->record($scope);

        $new = $this->ofKind($changes, ChangeKind::New);
        $regressions = $this->ofKind($changes, ChangeKind::Regression);

        $incidents = [];

        foreach ($this->correlator->correlate($scope) as $correlated) {
            $recorded = $this->recorder->record($correlated);

            if ($recorded['isNew']) {
                $incidents[] = $correlated;
            }
        }

        $announced = 0;
        $announced += $this->announceIssues($new, ChangeKind::New);
        $announced += $this->announceIssues($regressions, ChangeKind::Regression);
        $announced += $this->announceIncidents($incidents);

        $spikes = $this->spikes($scope, $changes);
        $announced += $this->announceSpikes($spikes);

        return new ScanResult(
            issuesSeen: count($changes),
            newIssues: count($new),
            regressions: count($regressions),
            incidents: count($incidents),
            spikes: count($spikes),
            announced: $announced,
        );
    }

    /**
     * Known issues firing materially harder than in the window before.
     *
     * Costs an extra read, so it only happens when a rule is watching for
     * one — a spike nobody asked about is not worth the query.
     *
     * @param  list<IssueChange>  $changes
     * @return list<Spike>
     */
    private function spikes(RequestScope $scope, array $changes): array
    {
        $rules = $this->rules(AlertType::IssueSpike);

        if ($rules === []) {
            return [];
        }

        $this->spikeRules = $rules;

        $counts = [];
        $types = [];

        foreach ($changes as $change) {
            // A brand new issue has no baseline to spike against; it is
            // already announced as new.
            if ($change->kind === ChangeKind::New) {
                continue;
            }

            $counts[$change->issue->fingerprint] = $change->count;
            $types[$change->issue->fingerprint] = $change->issue->type ?? 'Exception';
        }

        if ($counts === []) {
            return [];
        }

        $multiplier = max(1.5, (float) ($rules[0]->threshold ?? 3.0));

        return $this->spikes->against($scope, $counts, $multiplier, $types);
    }

    /**
     * @param  list<Spike>  $spikes
     */
    private function announceSpikes(array $spikes): int
    {
        if ($spikes === []) {
            return 0;
        }

        $worst = $spikes[0];

        foreach ($this->spikeRules as $rule) {
            $this->alerts->fire($rule, $worst->summary(), [
                'fingerprint' => $worst->fingerprint,
                'count' => $worst->count,
                'baseline' => $worst->baseline,
                'spiking' => count($spikes),
            ], $worst->multiplier);
        }

        return count($spikes);
    }

    /**
     * @param  list<IssueChange>  $changes
     * @return list<IssueChange>
     */
    private function ofKind(array $changes, ChangeKind $kind): array
    {
        return array_values(array_filter($changes, static fn (IssueChange $c): bool => $c->kind === $kind));
    }

    /**
     * @param  list<IssueChange>  $changes
     */
    private function announceIssues(array $changes, ChangeKind $kind): int
    {
        $key = $kind === ChangeKind::New ? 'new_issues' : 'regressions';

        if ($changes === [] || ! (bool) $this->config->get('telemetry-insights.announce.'.$key, true)) {
            return 0;
        }

        $sent = 0;

        foreach ($changes as $change) {
            $issue = $change->issue;

            // Someone already decided they do not want to hear about this.
            if (! $issue->effectiveStatus()->notifiable()) {
                continue;
            }

            $regression = $kind === ChangeKind::Regression;

            $this->notifier->send(new Notification(
                title: ($regression ? 'Regression: ' : 'New issue: ').($issue->type ?? 'Exception'),
                body: (string) ($issue->message ?? ''),
                severity: $regression ? Severity::Critical : Severity::Warning,
                facts: array_filter([
                    'Service' => (string) ($issue->service ?? ''),
                    'Occurrences' => (string) $change->count,
                    'Fingerprint' => $issue->fingerprint,
                ], static fn (string $v): bool => $v !== ''),
            ));

            $issue->forceFill(['notified_at' => Carbon::now()])->save();
            $sent++;
        }

        // A regression is its own alert type: it escaped a fix, which is a
        // different thing from something nobody has seen before.
        $this->fireEventRules(
            $kind === ChangeKind::Regression ? AlertType::Regression : AlertType::NewIssue,
            $changes,
        );

        return $sent;
    }

    /**
     * @param  list<CorrelatedIncident>  $incidents
     */
    private function announceIncidents(array $incidents): int
    {
        if ($incidents === [] || ! (bool) $this->config->get('telemetry-insights.announce.incidents', true)) {
            return 0;
        }

        foreach ($incidents as $incident) {
            $cause = $incident->cause;

            $this->notifier->send(new Notification(
                title: 'Incident: '.$incident->title(),
                body: $cause === null
                    ? count($incident->groups).' error groups started within moments of each other.'
                    : $cause->evidence,
                severity: Severity::Critical,
                facts: array_filter([
                    'Error groups' => (string) count($incident->groups),
                    'Occurrences' => (string) $incident->occurrences(),
                    'Services' => implode(', ', $incident->services()),
                    'Suspected cause' => $cause->label ?? 'not established',
                    'Confidence' => $cause->confidence->value ?? '',
                ], static fn (string $v): bool => $v !== ''),
            ));
        }

        foreach ($this->rules(AlertType::Incident) as $rule) {
            $first = $incidents[0];
            $this->alerts->fire($rule, 'Incident: '.$first->title(), [
                'signature' => $first->signature,
                'groups' => count($first->groups),
            ]);
        }

        return count($incidents);
    }

    /**
     * Event-shaped rules fire from the thing happening, not from a
     * measurement — the evaluator has nothing to measure for them.
     *
     * One firing per pass, naming the first and counting the rest: a rule
     * exists so someone hears once, not once per fingerprint.
     *
     * @param  list<IssueChange>  $changes
     */
    private function fireEventRules(AlertType $type, array $changes): void
    {
        if ($changes === []) {
            return;
        }

        $first = $changes[0];
        $others = count($changes) - 1;

        $summary = $type->label().': '.($first->issue->type ?? 'Exception')
            .($others > 0 ? ' (and '.$others.' other'.($others === 1 ? '' : 's').')' : '');

        foreach ($this->rules($type) as $rule) {
            $this->alerts->fire($rule, $summary, [
                'fingerprint' => $first->issue->fingerprint,
                'count' => count($changes),
            ]);
        }
    }

    /**
     * @return list<AlertRule>
     */
    private function rules(AlertType $type): array
    {
        /** @var list<AlertRule> $rules */
        $rules = AlertRule::query()
            ->where('enabled', true)
            ->where('type', $type->value)
            ->get()
            ->values()
            ->all();

        return $rules;
    }
}
