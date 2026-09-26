<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Correlate;

/**
 * One downstream a request talked to, identified well enough to recognise the
 * same one in another trace: the kind plus the address that names the actual
 * instance ("redis cache-1:6379"), never the statement or the key, which
 * differ per call and would make every span look like a different dependency.
 *
 * A zero-argument instance is meaningless, so the constructor takes the whole
 * identity; {@see key()} is the value tests and callers compare on.
 */
final readonly class DependencySignature
{
    public function __construct(
        public DependencyKind $kind,
        /** The system or protocol: `redis`, `mysql`, `sqs`, `https`. */
        public string $system,
        /** host:port, queue name, or peer host — whatever names the instance. */
        public string $target,
    ) {}

    /** Stable identity for grouping. */
    public function key(): string
    {
        return $this->kind->value.':'.$this->system.':'.$this->target;
    }

    /** "redis cache-1:6379" — for a human, in a sentence. */
    public function label(): string
    {
        if ($this->target === '') {
            return $this->system;
        }

        return $this->system === '' ? $this->target : $this->system.' '.$this->target;
    }
}
