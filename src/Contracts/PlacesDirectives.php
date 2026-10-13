<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Contracts;

use Cbox\TelemetryInsights\Profiling\DirectiveException;
use Cbox\TelemetryInsights\Profiling\DirectiveRequest;
use Cbox\TelemetryInsights\Profiling\PlacedDirective;

/**
 * Hands a directive to the app being monitored. Two ship: in-process, when
 * cboxdk/laravel-telemetry runs in this same app, and the signed HTTP
 * endpoint, for an app elsewhere.
 */
interface PlacesDirectives
{
    /**
     * @throws DirectiveException
     */
    public function place(DirectiveRequest $request): PlacedDirective;
}
