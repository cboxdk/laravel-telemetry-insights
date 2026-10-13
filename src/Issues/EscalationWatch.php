<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Issues;

use Cbox\TelemetryInsights\Models\Issue;
use Cbox\TelemetryInsights\Support\Setting;
use Cbox\TelemetryUi\Connectors\ConnectionManager;
use Cbox\TelemetryUi\Connectors\SourceException;
use Cbox\TelemetryUi\Http\Api\RequestScope;
use Cbox\TelemetryUi\Queries\Ir\MatchOp;
use Cbox\TelemetryUi\Queries\Results\LogEntry;
use DateTimeImmutable;

/**
 * Archive-until-escalating for error groups: learn what "normal" was when
 * the issue was archived, and say when it has gone past that.
 *
 * Both reads go to the exception records in the logs store, filtered to
 * the one fingerprint, so they cost one bounded query each — learning
 * happens once, at archive time; checking only for an archived group the
 * scan just saw fire.
 *
 * Browser exceptions live in traces, not logs, and are not counted here: an
 * archived frontend group stays archived until someone reopens it.
 */
class EscalationWatch
{
    private const PREFIX = 'telemetry-insights.escalation.';

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly Setting $settings,
    ) {}

    /**
     * The limits for this group, from its hourly volume over the baseline
     * days before $now. A store that will not answer gives the floor
     * limits, and says so — archiving must not fail because a read did.
     */
    public function learn(Issue $issue, ?int $now = null): EscalationLimits
    {
        $now ??= time();
        $days = max(1, $this->settings->int(self::PREFIX.'baseline_days', 7));
        $floor = $this->floor();

        try {
            $timestamps = $this->occurrences($issue, $now - $days * 86_400, $now, $this->sampleLimit());
        } catch (SourceException) {
            return new EscalationLimits($floor, $floor, note: 'the store did not answer; floor limits');
        }

        $start = $now - $days * 86_400;

        // A capped sample keeps the newest entries: count only the hours it
        // fully covers, or a busy group's quiet past reads as zero.
        if (count($timestamps) >= $this->sampleLimit() && $timestamps !== []) {
            $start = max($start, (int) (ceil(min($timestamps) / 3600) * 3600));
        }

        $hours = max(1, intdiv($now - $start, 3600));
        $counts = array_fill(0, $hours, 0);

        foreach ($timestamps as $ts) {
            $index = intdiv($ts - $start, 3600);

            if ($index >= 0 && $index < $hours) {
                $counts[$index]++;
            }
        }

        sort($counts);

        $typical = $counts[(int) floor(0.95 * (count($counts) - 1))];
        $peak = $counts[count($counts) - 1];

        $spike = max($floor, (int) ceil($typical * $this->settings->float(self::PREFIX.'spike_factor', 3.0)));
        $burst = max($spike, (int) ceil($peak * $this->settings->float(self::PREFIX.'burst_factor', 1.5)));

        return new EscalationLimits($spike, $burst, $hours);
    }

    /**
     * Whether an archived group is escalating now: over its burst limit in
     * the last hour, or over its spike limit in each of the last N hours.
     * Null for "not escalating" and for "could not tell" alike — a failed
     * read must never reopen anything.
     */
    public function check(Issue $issue, ?int $now = null): ?Escalation
    {
        $now ??= time();
        $sustained = $this->sustainedHours();
        $floor = $this->floor();
        $spike = $issue->escalation_spike_limit ?? $floor;
        $burst = max($spike, $issue->escalation_burst_limit ?? $floor);

        try {
            $timestamps = $this->occurrences($issue, $now - $sustained * 3600, $now, min($this->sampleLimit(), ($burst + 1) * $sustained + 1));
        } catch (SourceException) {
            return null;
        }

        $hourly = array_fill(0, $sustained, 0);

        foreach ($timestamps as $ts) {
            $index = intdiv(max(0, $now - $ts - 1), 3600);

            if ($index < $sustained) {
                $hourly[$index]++;
            }
        }

        if ($hourly[0] > $burst) {
            return new Escalation(array_values($hourly), $burst, $hourly[0].' in the last hour, over its burst limit of '.$burst.'.');
        }

        $over = array_filter($hourly, static fn (int $count): bool => $count > $spike);

        if (count($over) === $sustained) {
            return new Escalation(array_values($hourly), $spike, 'over its spike limit of '.$spike.' an hour for '.$sustained.' hours running ('.implode(', ', array_reverse($hourly)).').');
        }

        return null;
    }

    public function sustainedHours(): int
    {
        return max(1, min(24, $this->settings->int(self::PREFIX.'sustained_hours', 2)));
    }

    private function floor(): int
    {
        return max(1, $this->settings->int(self::PREFIX.'min_volume', 10));
    }

    private function sampleLimit(): int
    {
        return max(100, $this->settings->int(self::PREFIX.'sample_limit', 10_000));
    }

    /**
     * Unix seconds of each occurrence of this group in the range.
     *
     * @return list<int>
     */
    private function occurrences(Issue $issue, int $from, int $to, int $limit): array
    {
        $scope = new RequestScope(service: $issue->service ?? '');

        $entries = $this->connections->logs()->query(
            $scope->logQuery()->whereLabel('exception_group', MatchOp::Eq, $issue->fingerprint),
            new DateTimeImmutable('@'.$from),
            new DateTimeImmutable('@'.$to),
            $limit,
        );

        return array_map(static fn (LogEntry $e): int => intdiv($e->timestampNano, 1_000_000_000), $entries);
    }
}
