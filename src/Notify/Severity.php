<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Notify;

enum Severity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';

    /** Slack/Block Kit colour rail. */
    public function color(): string
    {
        return match ($this) {
            self::Info => '#4b6bfb',
            self::Warning => '#d97706',
            self::Critical => '#dc2626',
        };
    }

    public function emoji(): string
    {
        return match ($this) {
            self::Info => ':information_source:',
            self::Warning => ':warning:',
            self::Critical => ':rotating_light:',
        };
    }
}
