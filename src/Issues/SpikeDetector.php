<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Issues;

use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Explore\ErrorExplorer;
use Cbox\TelemetryUi\Http\Api\RequestScope;

/**
 * Finds known issues firing materially harder than they were.
 *
 * The baseline is the window immediately before this one, of the same
 * length — not a long-run average. A rate that doubled in the last ten
 * minutes is the thing worth interrupting someone for; a rate that has been
 * creeping up all month belongs in the digest.
 *
 * Costs one extra read, so callers only reach for it when something is
 * actually watching for a spike.
 */
class SpikeDetector
{
    /**
     * An issue must reach this many occurrences before a multiple means
     * anything. Without it, one occurrence becoming three is a 3× "spike".
     */
    private const FLOOR = 5;

    public function __construct(private readonly ErrorExplorer $errors) {}

    /**
     * @param  array<string, int>  $current  fingerprint => occurrences in this window
     * @param  array<string, string>  $types  fingerprint => exception class, for the message
     * @return list<Spike>
     */
    public function against(RequestScope $scope, array $current, float $multiplier, array $types = []): array
    {
        $baseline = $this->baseline($scope);

        if ($baseline === null) {
            return [];
        }

        $spikes = [];

        foreach ($current as $fingerprint => $count) {
            if ($count < self::FLOOR) {
                continue;
            }

            $before = $baseline[$fingerprint] ?? 0;

            // Never seen before is a new issue, which is already its own
            // announcement — not a spike.
            if ($before === 0) {
                continue;
            }

            $ratio = $count / $before;

            if ($ratio < $multiplier) {
                continue;
            }

            $spikes[] = new Spike(
                fingerprint: $fingerprint,
                type: $types[$fingerprint] ?? 'Exception',
                count: $count,
                baseline: $before,
                multiplier: $ratio,
            );
        }

        usort($spikes, static fn (Spike $a, Spike $b): int => $b->multiplier <=> $a->multiplier);

        return $spikes;
    }

    /**
     * Occurrences per fingerprint in the window immediately before this one.
     * Null when it could not be read — which must not read as "zero
     * everywhere", or every issue would look like an infinite spike.
     *
     * @return array<string, int>|null
     */
    private function baseline(RequestScope $scope): ?array
    {
        [$start, $end] = $scope->range();
        $length = max(1, $end->getTimestamp() - $start->getTimestamp());

        $previous = new RequestScope(
            from: (string) ($start->getTimestamp() - $length),
            to: (string) $start->getTimestamp(),
            service: $scope->service,
            environment: $scope->environment,
            where: $scope->where,
        );

        try {
            $occurrences = $this->errors->occurrences($previous);
        } catch (SourceException) {
            return null;
        }

        $counts = [];

        foreach ($occurrences as $occurrence) {
            $fingerprint = $occurrence['group'];
            $counts[$fingerprint] = ($counts[$fingerprint] ?? 0) + 1;
        }

        return $counts;
    }
}
