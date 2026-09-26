<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Alerts;

/**
 * What an alert watches.
 *
 * Deliberately weighted towards things that already mean something — a new
 * issue, a correlated incident — over raw numbers. A threshold on a metric
 * tells you a number moved; "a dependency died and took nine call sites with
 * it" tells you what to do. {@see self::Metric} is the escape hatch for when
 * you really do want the number.
 */
enum AlertType: string
{
    /** A fingerprint nobody has seen before starts firing. */
    case NewIssue = 'new_issue';

    /** A resolved issue starts firing again. */
    case Regression = 'regression';

    /** A known issue's rate jumps against its own recent baseline. */
    case IssueSpike = 'issue_spike';

    /** The correlator opened an incident. */
    case Incident = 'incident';

    /** Share of requests ending 5xx, over the window. */
    case ErrorRate = 'error_rate';

    /** Request p95 latency, in milliseconds, over the window. */
    case LatencyP95 = 'latency_p95';

    /** Requests per minute, over the window. */
    case Throughput = 'throughput';

    /** Any metric by name — the escape hatch. */
    case Metric = 'metric';

    public function label(): string
    {
        return match ($this) {
            self::NewIssue => 'New issue',
            self::Regression => 'Regression',
            self::IssueSpike => 'Issue spike',
            self::Incident => 'Incident opened',
            self::ErrorRate => 'Error rate',
            self::LatencyP95 => 'Latency (p95)',
            self::Throughput => 'Throughput',
            self::Metric => 'Metric threshold',
        };
    }

    public function unit(): string
    {
        return match ($this) {
            self::ErrorRate => '%',
            self::LatencyP95 => 'ms',
            self::Throughput => 'req/min',
            default => '',
        };
    }

    /**
     * Event-shaped types fire on something happening, not on a measurement
     * crossing a line, so they skip the comparator entirely.
     */
    public function isEvent(): bool
    {
        return in_array($this, [self::NewIssue, self::Regression, self::Incident], true);
    }
}
