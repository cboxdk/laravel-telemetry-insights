<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Correlate;

use Cbox\TelemetryUi\Queries\Results\Span;
use Cbox\TelemetryUi\Queries\Results\SpanKind;
use Cbox\TelemetryUi\Queries\Results\Trace;

/**
 * Reads the downstreams out of a trace: every database, cache, queue and HTTP
 * peer the request talked to, and whether the call to it failed.
 *
 * Only outbound spans count — a server span is the request itself, not
 * something it depends on. The identity is the instance
 * (`db.system.name` + `server.address`), never the statement or the cache
 * key, so two calls to the same Redis node look like the same dependency.
 */
final class TraceDependencies
{
    /**
     * Every distinct dependency in the trace, with whether any call to it
     * errored.
     *
     * @return array<string, array{signature: DependencySignature, failed: bool}>
     *                                                                            keyed by {@see DependencySignature::key()}
     */
    public function of(Trace $trace): array
    {
        $found = [];

        foreach ($trace->spans as $span) {
            $signature = self::signature($span);

            if ($signature === null) {
                continue;
            }

            $key = $signature->key();
            $found[$key] ??= ['signature' => $signature, 'failed' => false];

            if ($span->hasError) {
                $found[$key]['failed'] = true;
            }
        }

        return $found;
    }

    /**
     * The dependency one span represents, or null when the span is not an
     * outbound call.
     */
    public static function signature(Span $span): ?DependencySignature
    {
        if (! in_array($span->kind, [SpanKind::Client, SpanKind::Producer], true)) {
            return null;
        }

        $attributes = $span->attributes;
        $system = self::string($attributes, 'db.system.name');
        $address = self::address($attributes);

        if ($system !== '') {
            // Redis, Memcached and friends are databases in the semantic
            // conventions but a cache in the operator's head, and "the cache
            // is down" is the sentence they want to read.
            $kind = in_array($system, ['redis', 'memcached', 'valkey'], true)
                ? DependencyKind::Cache
                : DependencyKind::Database;

            return new DependencySignature($kind, $system, $address);
        }

        $messaging = self::string($attributes, 'messaging.system');

        if ($messaging !== '') {
            $destination = self::string($attributes, 'messaging.destination.name');

            return new DependencySignature(
                DependencyKind::Queue,
                $messaging,
                $destination !== '' ? $destination : $address,
            );
        }

        // An outbound HTTP call: the peer is the dependency. Without an
        // address there is nothing to recognise it by in another trace.
        if ($address !== '' && self::string($attributes, 'http.request.method') !== '') {
            return new DependencySignature(DependencyKind::Http, 'http', $address);
        }

        return null;
    }

    /**
     * host:port, preferring the semantic-convention `server.*` over the
     * deprecated `network.peer.*` the older instrumentation emitted.
     *
     * @param  array<string, mixed>  $attributes
     */
    private static function address(array $attributes): string
    {
        $host = self::string($attributes, 'server.address');
        $port = self::string($attributes, 'server.port');

        if ($host === '') {
            $host = self::string($attributes, 'network.peer.address');
            $port = self::string($attributes, 'network.peer.port');
        }

        if ($host === '') {
            return '';
        }

        return $port === '' ? $host : $host.':'.$port;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function string(array $attributes, string $key): string
    {
        $value = $attributes[$key] ?? null;

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
