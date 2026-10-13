<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Issues;

/** Which door a decision about an issue came through. */
enum ActionSource: string
{
    /** `telemetry-insights:issue` in a terminal. */
    case Console = 'console';

    /** The HTTP API, usually the dashboard's row menu. */
    case Api = 'api';

    /** An assistant, through the MCP tools. */
    case Agent = 'agent';

    /** The scan itself: a condition cleared, or an issue came back. */
    case Scan = 'scan';

    /** The host's own code, calling {@see IssueActions} directly. */
    case Code = 'code';

    public function label(): string
    {
        return match ($this) {
            self::Console => 'via console',
            self::Api => 'via API',
            self::Agent => 'via agent',
            self::Scan => 'automatically',
            self::Code => 'via code',
        };
    }
}
