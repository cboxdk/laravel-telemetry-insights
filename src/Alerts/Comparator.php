<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Alerts;

enum Comparator: string
{
    case Above = 'above';
    case Below = 'below';

    public function breached(float $value, float $threshold): bool
    {
        return match ($this) {
            self::Above => $value > $threshold,
            self::Below => $value < $threshold,
        };
    }

    public function symbol(): string
    {
        return $this === self::Above ? '>' : '<';
    }
}
