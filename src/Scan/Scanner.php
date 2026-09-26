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
    public function __construct(
        private readonly IssueLedger $ledger,
        private readonly CorrelatesIncidents $correlator,
        private readonly IncidentRecorder $recorder,
        private readonly Notifier $notifier,
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

        return new ScanResult(
            issuesSeen: count($changes),
            newIssues: count($new),
            regressions: count($regressions),
            incidents: count($incidents),
            announced: $announced,
        );
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

        $this->fireEventRules(AlertType::NewIssue, $changes[0] ?? null);

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
            $this->notifier->send(new Notification(
                title: 'Incident: '.$incident->title(),
                body: $incident->cause?->evidence
                    ?? count($incident->groups).' error groups started within moments of each other.',
                severity: Severity::Critical,
                facts: array_filter([
                    'Error groups' => (string) count($incident->groups),
                    'Occurrences' => (string) $incident->occurrences(),
                    'Services' => implode(', ', $incident->services()),
                    'Suspected cause' => $incident->cause?->label ?? 'not established',
                    'Confidence' => $incident->cause?->confidence->value ?? '',
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
     */
    private function fireEventRules(AlertType $type, ?IssueChange $change): void
    {
        if ($change === null) {
            return;
        }

        foreach ($this->rules($type) as $rule) {
            $this->alerts->fire($rule, 'New issue: '.($change->issue->type ?? 'Exception'), [
                'fingerprint' => $change->issue->fingerprint,
            ]);
        }
    }

    /**
     * @return list<AlertRule>
     */
    private function rules(AlertType $type): array
    {
        return AlertRule::query()
            ->where('enabled', true)
            ->where('type', $type->value)
            ->get()
            ->all();
    }
}
