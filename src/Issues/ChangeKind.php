<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Issues;

/** What happened to an issue on this pass over the window. */
enum ChangeKind: string
{
    /** A fingerprint this install has never recorded before. */
    case New = 'new';

    /** Fired again after someone marked it resolved. */
    case Regression = 'regression';

    /** Already open, still going. Not news. */
    case Recurring = 'recurring';

    /** Worth telling someone about, as opposed to merely true. */
    public function isNews(): bool
    {
        return $this !== self::Recurring;
    }
}
