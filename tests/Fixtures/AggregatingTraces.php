<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Tests\Fixtures;

use Cbox\TelemetryUi\Contracts\AggregatesSpans;
use Cbox\TelemetryUi\Contracts\TracesSource;
use Cbox\TelemetryUi\Queries\Ir\SpanAggregation;
use Cbox\TelemetryUi\Queries\Ir\TraceQuery;
use Cbox\TelemetryUi\Queries\Results\SpanBucket;
use Cbox\TelemetryUi\Queries\Results\Trace;
use Cbox\TelemetryUi\Queries\Results\TraceSummary;
use DateTimeInterface;

/**
 * A traces source that can aggregate spans server-side, the way the
 * ClickHouse store can — so the digest's query findings are exercised
 * against the contract rather than against a mock of the digest.
 */
final class AggregatingTraces implements AggregatesSpans, TracesSource
{
    /**
     * @param  list<SpanBucket>  $buckets
     */
    public function __construct(private readonly array $buckets = []) {}

    /**
     * @return list<SpanBucket>
     */
    public function aggregateSpans(SpanAggregation $aggregation, DateTimeInterface $start, DateTimeInterface $end): array
    {
        return $this->buckets;
    }

    /**
     * @return list<TraceSummary>
     */
    public function search(TraceQuery $query, DateTimeInterface $start, DateTimeInterface $end, int $limit = 20): array
    {
        return [];
    }

    public function trace(string $traceId): Trace
    {
        return new Trace($traceId, []);
    }

    /**
     * @return list<string>
     */
    public function tagValues(
        string $tag,
        ?TraceQuery $filter = null,
        ?DateTimeInterface $start = null,
        ?DateTimeInterface $end = null,
        int $limit = 0,
    ): array {
        return [];
    }
}
