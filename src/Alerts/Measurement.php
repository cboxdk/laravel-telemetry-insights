<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Alerts;

/**
 * What a rule's watched thing actually was, this time.
 *
 * `null` value means "could not be measured" — a backend that was down, a
 * metric that does not exist yet — which is deliberately different from
 * zero. A rule that fires on `< 1` must not page because Prometheus was
 * unreachable.
 */
final readonly class Measurement
{
    public function __construct(
        public ?float $value,
        public string $unit = '',
        /** Why there is no value, when there is none. */
        public ?string $reason = null,
    ) {}

    public static function unavailable(string $reason): self
    {
        return new self(null, '', $reason);
    }

    public function isAvailable(): bool
    {
        return $this->value !== null;
    }

    public function format(): string
    {
        if ($this->value === null) {
            return 'unavailable';
        }

        $rounded = abs($this->value) >= 100 ? round($this->value) : round($this->value, 2);

        return rtrim(rtrim(number_format($rounded, 2, '.', ''), '0'), '.').$this->unit;
    }
}
