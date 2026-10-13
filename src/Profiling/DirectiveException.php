<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Profiling;

use RuntimeException;

/** A directive could not be placed: no transport, refused, or unreachable. */
final class DirectiveException extends RuntimeException {}
