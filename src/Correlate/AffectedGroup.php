<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Correlate;

/**
 * One error group caught up in an incident: the fingerprint the rest of the
 * stack already groups by, when it started firing in this window, and a trace
 * to look at.
 */
final readonly class AffectedGroup
{
    public function __construct(
        /** The exception fingerprint — the same id the dashboard's issue pages use. */
        public string $fingerprint,
        public string $type,
        public string $message,
        /** Earliest occurrence seen in the window. */
        public int $onsetNano,
        public int $count,
        public string $service,
        public ?string $sampleTraceId = null,
    ) {}

    /**
     * @return array{fingerprint: string, type: string, message: string, onsetNano: int, count: int, service: string, sampleTraceId: string|null}
     */
    public function toArray(): array
    {
        return [
            'fingerprint' => $this->fingerprint,
            'type' => $this->type,
            'message' => $this->message,
            'onsetNano' => $this->onsetNano,
            'count' => $this->count,
            'service' => $this->service,
            'sampleTraceId' => $this->sampleTraceId,
        ];
    }
}
