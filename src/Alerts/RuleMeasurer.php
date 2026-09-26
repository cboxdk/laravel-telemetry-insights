<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Alerts;

use Cbox\TelemetryInsights\Models\AlertRule;
use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Queries\Results\Sample;

/**
 * Turns a rule into a number, through the same backend-neutral query IR the
 * dashboard uses.
 *
 * Going through the IR rather than writing PromQL is what lets one rule work
 * against Prometheus, Mimir or a SQL store without knowing which is behind
 * it. Nothing here compiles a query string.
 */
class RuleMeasurer
{
    /** The request-duration histogram cboxdk/laravel-telemetry emits. */
    private const REQUESTS = 'http_server_request_duration_seconds';

    public function __construct(private readonly ConnectionManager $connections) {}

    public function measure(AlertRule $rule): Measurement
    {
        return match ($rule->type) {
            AlertType::ErrorRate => $this->errorRate($rule),
            AlertType::LatencyP95 => $this->latencyP95($rule),
            AlertType::Throughput => $this->throughput($rule),
            AlertType::Metric => $this->metric($rule),
            // Event-shaped rules are fired by the scan, not measured here.
            AlertType::NewIssue, AlertType::IssueSpike, AlertType::Incident => Measurement::unavailable(
                'This rule fires on an event, not a measurement.',
            ),
        };
    }

    /** Share of requests answering 5xx, as a percentage. */
    private function errorRate(AlertRule $rule): Measurement
    {
        $scope = $rule->scope();
        $counter = $scope->metricQuery(self::REQUESTS.'_count');

        try {
            $byStatus = $this->connections->metrics()->query(
                $counter->increase($scope->promDuration())->sumBy('http_response_status_code'),
            );
        } catch (SourceException $e) {
            return Measurement::unavailable($e->getMessage());
        }

        $total = 0.0;
        $failed = 0.0;

        foreach ($byStatus as $sample) {
            $total += $sample->value;

            if (str_starts_with((string) ($sample->labels['http_response_status_code'] ?? ''), '5')) {
                $failed += $sample->value;
            }
        }

        // No traffic is not a 0% error rate — it is no measurement. A rule
        // watching for a spike must not read "all clear" from an idle night.
        if ($total <= 0.0) {
            return Measurement::unavailable('No requests in the window.');
        }

        return new Measurement($failed / $total * 100, '%');
    }

    private function latencyP95(AlertRule $rule): Measurement
    {
        $scope = $rule->scope();
        $bucket = $scope->metricQuery(self::REQUESTS.'_bucket');

        try {
            // The histogram is in seconds; the threshold is in milliseconds,
            // because nobody sets a latency budget in seconds.
            $samples = $this->connections->metrics()->query(
                $bucket->quantile(0.95, $scope->promDuration())->times(1000),
            );
        } catch (SourceException $e) {
            return Measurement::unavailable($e->getMessage());
        }

        $value = $this->first($samples);

        return $value === null
            ? Measurement::unavailable('No latency samples in the window.')
            : new Measurement($value, 'ms');
    }

    private function throughput(AlertRule $rule): Measurement
    {
        $scope = $rule->scope();
        $counter = $scope->metricQuery(self::REQUESTS.'_count');

        try {
            $samples = $this->connections->metrics()->query(
                $counter->rate($scope->rateWindow())->sumBy()->times(60),
            );
        } catch (SourceException $e) {
            return Measurement::unavailable($e->getMessage());
        }

        $value = $this->first($samples);

        return $value === null
            ? Measurement::unavailable('No throughput samples in the window.')
            : new Measurement($value, ' req/min');
    }

    /** The escape hatch: any metric, summed over the scope. */
    private function metric(AlertRule $rule): Measurement
    {
        $name = $rule->metric;

        if ($name === null || $name === '') {
            return Measurement::unavailable('The rule has no metric name.');
        }

        $scope = $rule->scope();

        try {
            $samples = $this->connections->metrics()->query($scope->metricQuery($name)->sumBy());
        } catch (SourceException $e) {
            return Measurement::unavailable($e->getMessage());
        }

        $value = $this->first($samples);

        return $value === null
            ? Measurement::unavailable('No samples for '.$name.' in the window.')
            : new Measurement($value);
    }

    /**
     * @param  list<Sample>  $samples
     */
    private function first(array $samples): ?float
    {
        foreach ($samples as $sample) {
            if (is_finite($sample->value)) {
                return $sample->value;
            }
        }

        return null;
    }
}
