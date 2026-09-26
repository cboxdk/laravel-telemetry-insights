<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Correlate;

/**
 * Several error groups that started together and look like one event.
 *
 * This is the thing a dashboard cannot tell you and a stack trace cannot
 * either: when a cache node dies, fifty unrelated fingerprints light up in
 * fifty places in the code, and paging on each of them is noise. An incident
 * is that burst, folded back into the one thing that went wrong.
 */
final readonly class Incident
{
    /**
     * @param  list<AffectedGroup>  $groups
     */
    public function __construct(
        public string $signature,
        public int $onsetNano,
        public array $groups,
        public ?SuspectedCause $cause = null,
    ) {}

    /** Total occurrences across every group in the burst. */
    public function occurrences(): int
    {
        return array_sum(array_map(static fn (AffectedGroup $g): int => $g->count, $this->groups));
    }

    /** @return list<string> */
    public function fingerprints(): array
    {
        return array_map(static fn (AffectedGroup $g): string => $g->fingerprint, $this->groups);
    }

    /** @return list<string> */
    public function services(): array
    {
        return array_values(array_unique(array_map(
            static fn (AffectedGroup $g): string => $g->service,
            $this->groups,
        )));
    }

    /**
     * A headline a human reads first: what broke, and how wide it went.
     */
    public function title(): string
    {
        $spread = count($this->groups).' error '.(count($this->groups) === 1 ? 'group' : 'groups');

        if ($this->cause === null) {
            return $spread.' started together';
        }

        return $this->cause->kind === CauseKind::Dependency
            ? $this->cause->label.' — '.$spread.' affected'
            : $this->cause->label.' — '.$spread.' started after it';
    }

    /**
     * A stable id for the same incident seen again on the next run.
     *
     * Keyed on the cause and the hour it started, NOT on the set of
     * fingerprints: a burst picks up more groups as it goes, and an id that
     * changed every time a fifty-first error joined would file a new incident
     * on every pass. Without a cause there is nothing stabler than the
     * members, so those are used instead.
     *
     * @param  list<AffectedGroup>  $groups
     */
    public static function signature(int $onsetNano, array $groups, ?SuspectedCause $cause): string
    {
        $hour = intdiv($onsetNano, 3_600_000_000_000);

        if ($cause !== null) {
            return substr(hash('sha256', $cause->kind->value.':'.$cause->label.':'.$hour), 0, 12);
        }

        $fingerprints = array_map(static fn (AffectedGroup $g): string => $g->fingerprint, $groups);
        sort($fingerprints);

        return substr(hash('sha256', implode(',', $fingerprints).':'.$hour), 0, 12);
    }

    /**
     * @return array{signature: string, onsetNano: int, title: string, occurrences: int, services: list<string>, cause: array{kind: string, label: string, evidence: string, confidence: string, traceId: string|null}|null, groups: list<array{fingerprint: string, type: string, message: string, onsetNano: int, count: int, service: string, sampleTraceId: string|null}>}
     */
    public function toArray(): array
    {
        return [
            'signature' => $this->signature,
            'onsetNano' => $this->onsetNano,
            'title' => $this->title(),
            'occurrences' => $this->occurrences(),
            'services' => $this->services(),
            'cause' => $this->cause?->toArray(),
            'groups' => array_map(static fn (AffectedGroup $g): array => $g->toArray(), $this->groups),
        ];
    }
}
