<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Digest;

/** The sort of thing a digest turned up. */
enum FindingKind: string
{
    case Incident = 'incident';
    case NewIssue = 'new_issue';
    case Regression = 'regression';
    case SlowRoute = 'slow_route';
    case SlowQuery = 'slow_query';
    case RepeatedQuery = 'repeated_query';

    public function label(): string
    {
        return match ($this) {
            self::Incident => 'Incident',
            self::NewIssue => 'New issue',
            self::Regression => 'Regression',
            self::SlowRoute => 'Slow route',
            self::SlowQuery => 'Slow query',
            self::RepeatedQuery => 'Repeated query',
        };
    }

    /**
     * Ranking weight. An incident outranks everything: it is the one finding
     * that already explains several others.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Incident => 100,
            self::Regression => 80,
            self::NewIssue => 60,
            self::SlowQuery => 40,
            self::RepeatedQuery => 30,
            self::SlowRoute => 20,
        };
    }
}
