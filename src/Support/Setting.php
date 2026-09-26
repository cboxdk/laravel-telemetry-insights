<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Support;

use Illuminate\Contracts\Config\Repository as Config;

/**
 * Config values as the types the code actually wants.
 *
 * Config is `mixed` by definition — a published file is edited by hand and
 * an env var is always a string. Casting at each call site either lies to
 * the analyser or litters the code; reading through here does neither, and
 * a nonsense value falls back to the default instead of becoming 0.
 */
final readonly class Setting
{
    public function __construct(private Config $config) {}

    public function int(string $key, int $default): int
    {
        $value = $this->config->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function float(string $key, float $default): float
    {
        $value = $this->config->get($key, $default);

        return is_numeric($value) ? (float) $value : $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->config->get($key, $default);

        return is_string($value) ? $value : $default;
    }

    public function bool(string $key, bool $default): bool
    {
        $value = $this->config->get($key, $default);

        return is_bool($value) ? $value : $default;
    }
}
