<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Correlate;

/**
 * What an incident is blamed on, and why — the "why" in words, because a
 * cause a human cannot check is a cause they cannot trust.
 */
final readonly class SuspectedCause
{
    public function __construct(
        public CauseKind $kind,
        /** The thing itself: "redis cache-1:6379", "deploy v2.4.1". */
        public string $label,
        /** One sentence naming the evidence that produced this verdict. */
        public string $evidence,
        public Confidence $confidence,
        /** A trace that shows it, when one does. */
        public ?string $traceId = null,
    ) {}

    /** Beats another cause when it explains more, or explains as much more firmly. */
    public function beats(?self $other): bool
    {
        if ($other === null) {
            return true;
        }

        if ($this->kind->rank() !== $other->kind->rank()) {
            return $this->kind->rank() > $other->kind->rank();
        }

        return $this->confidence->rank() > $other->confidence->rank();
    }

    /**
     * @return array{kind: string, label: string, evidence: string, confidence: string, traceId: string|null}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'label' => $this->label,
            'evidence' => $this->evidence,
            'confidence' => $this->confidence->value,
            'traceId' => $this->traceId,
        ];
    }
}
