<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Issues;

/**
 * Who changed an issue, through which door, and why.
 *
 * Written to the issue's history with every decision, so "who resolved
 * this, and was it a person?" has an answer — an agent closing issues on a
 * team's behalf is only acceptable if the record says it did.
 */
final readonly class Attribution
{
    /** The name the scan records itself under. */
    public const SCAN_ACTOR = 'telemetry-insights';

    public function __construct(
        public ActionSource $source = ActionSource::Code,
        public ?string $actor = null,
        public ?string $comment = null,
    ) {}

    /**
     * Whether a name belongs to the system. A caller may not claim it: the
     * history would then say a person's decision was automatic.
     */
    public static function reserved(?string $actor): bool
    {
        return $actor !== null && strcasecmp(trim($actor), self::SCAN_ACTOR) === 0;
    }

    public static function scan(?string $comment = null): self
    {
        return new self(ActionSource::Scan, self::SCAN_ACTOR, $comment);
    }

    public static function agent(?string $actor, ?string $comment = null): self
    {
        return new self(ActionSource::Agent, $actor, $comment);
    }
}
