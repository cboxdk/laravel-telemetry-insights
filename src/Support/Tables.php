<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Support;

/**
 * Table names, resolved through config so a host can rename them without
 * forking the migration. Defaults are unprefixed and readable; set
 * `telemetry-insights.tables.*` to change one.
 */
final class Tables
{
    public static function issues(): string
    {
        return self::name('issues', 'telemetry_issues');
    }

    public static function incidents(): string
    {
        return self::name('incidents', 'telemetry_incidents');
    }

    public static function alertRules(): string
    {
        return self::name('alert_rules', 'telemetry_alert_rules');
    }

    public static function alertEvents(): string
    {
        return self::name('alert_events', 'telemetry_alert_events');
    }

    private static function name(string $key, string $default): string
    {
        $value = config('telemetry-insights.tables.'.$key, $default);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
