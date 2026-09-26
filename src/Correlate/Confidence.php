<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Correlate;

/**
 * How strongly the evidence supports a cause. Deliberately three coarse
 * steps, not a percentage: the inputs are heuristics over sampled telemetry,
 * and a number like "0.83" would imply a precision we do not have.
 */
enum Confidence: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    public function rank(): int
    {
        return match ($this) {
            self::High => 3,
            self::Medium => 2,
            self::Low => 1,
        };
    }
}
