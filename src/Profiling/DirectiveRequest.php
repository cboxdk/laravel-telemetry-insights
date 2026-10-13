<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Profiling;

use InvalidArgumentException;

/**
 * "Record more of this slice, for a while" — a directive as this package
 * asks for one. The monitored app decides the id and enforces its own
 * limits; this is only the request.
 *
 * The shape is cboxdk/laravel-telemetry's: match one of route, path, user,
 * context (with a key) or job; a pattern or value; minutes; whether to
 * CPU-profile; why; and who asked.
 */
final readonly class DirectiveRequest
{
    public const MATCHES = ['route', 'path', 'user', 'context', 'job'];

    public function __construct(
        public string $match,
        public string $value,
        public int $minutes = 60,
        public bool $profile = false,
        public ?string $reason = null,
        public string $createdBy = 'insights',
        /** For `context`: the dimension, e.g. `tenant.id`. */
        public ?string $key = null,
        /** The service it is for; null means this app. */
        public ?string $service = null,
    ) {
        if (! in_array($match, self::MATCHES, true)) {
            throw new InvalidArgumentException('A directive matches one of: '.implode(', ', self::MATCHES).'.');
        }

        if (trim($value) === '') {
            throw new InvalidArgumentException('A directive needs a value to match.');
        }

        if ($match === 'context' && ($key === null || trim($key) === '')) {
            throw new InvalidArgumentException('A context directive needs a key, e.g. tenant.id.');
        }

        if ($minutes < 1) {
            throw new InvalidArgumentException('A directive lasts at least a minute.');
        }
    }

    /**
     * The body the signed endpoint takes.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return array_filter([
            'match' => $this->match,
            'key' => $this->key,
            'value' => $this->value,
            'minutes' => $this->minutes,
            'profile' => $this->profile,
            'reason' => $this->reason,
            'created_by' => $this->createdBy,
        ], static fn (mixed $v): bool => $v !== null);
    }

    public function describe(): string
    {
        return ($this->key !== null ? $this->match.' '.$this->key.'='.$this->value : $this->match.' '.$this->value)
            .' for '.$this->minutes.'m'.($this->profile ? ' with a CPU profile' : '');
    }
}
