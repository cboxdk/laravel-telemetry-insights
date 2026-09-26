<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Correlate;

/**
 * The sort of downstream a span talks to. Derived from the OTLP attributes
 * cboxdk/laravel-telemetry emits, not guessed from the span name.
 */
enum DependencyKind: string
{
    case Database = 'database';
    case Cache = 'cache';
    case Queue = 'queue';
    case Http = 'http';

    public function label(): string
    {
        return match ($this) {
            self::Database => 'database',
            self::Cache => 'cache',
            self::Queue => 'queue',
            self::Http => 'HTTP service',
        };
    }
}
