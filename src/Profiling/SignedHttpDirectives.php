<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Profiling;

use Cbox\TelemetryInsights\Contracts\PlacesDirectives;
use Illuminate\Http\Client\Factory as Http;
use Throwable;

/**
 * Directives for an app elsewhere, through the signed endpoint
 * cboxdk/laravel-telemetry exposes when `TELEMETRY_DIRECTIVES_SECRET` is
 * set:
 *
 *     X-Telemetry-Timestamp: <unix seconds>
 *     X-Telemetry-Signature: sha256=<hex HMAC-SHA256 of "<timestamp>.<raw body>">
 *
 * The body is signed exactly as sent, so it is encoded once and passed
 * through untouched.
 */
final readonly class SignedHttpDirectives implements PlacesDirectives
{
    public function __construct(
        private Http $http,
        /** The app's base URL, e.g. https://shop.example. */
        private string $url,
        private string $secret,
        private string $path = 'telemetry/directives',
        private int $timeout = 5,
    ) {}

    public function place(DirectiveRequest $request): PlacedDirective
    {
        $body = (string) json_encode($request->payload(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = (string) time();

        try {
            $response = $this->http
                ->timeout($this->timeout)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'X-Telemetry-Timestamp' => $timestamp,
                    'X-Telemetry-Signature' => self::sign($timestamp, $body, $this->secret),
                ])
                ->withBody($body, 'application/json')
                ->post(rtrim($this->url, '/').'/'.ltrim($this->path, '/'));
        } catch (Throwable $e) {
            throw new DirectiveException('Could not reach '.$this->url.': '.$e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            $error = $response->json('error');

            throw new DirectiveException('The app refused the directive ('.$response->status().')'
                .(is_string($error) ? ': '.$error : '.'));
        }

        $id = $response->json('directive.id');
        $expires = $response->json('directive.expires_at');

        if (! is_string($id)) {
            throw new DirectiveException('The app answered without a directive id.');
        }

        return new PlacedDirective($id, is_int($expires) ? $expires : time() + $request->minutes * 60);
    }

    public static function sign(string $timestamp, string $body, string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }
}
