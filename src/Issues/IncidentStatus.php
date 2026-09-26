<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Issues;

enum IncidentStatus: string
{
    case Open = 'open';
    case Acknowledged = 'acknowledged';
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Acknowledged => 'Acknowledged',
            self::Resolved => 'Resolved',
        };
    }
}
