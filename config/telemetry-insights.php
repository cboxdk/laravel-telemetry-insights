<?php

declare(strict_types=1);

use Cbox\TelemetryInsights\Notify\LogChannel;
use Cbox\TelemetryInsights\Notify\SlackChannel;
use Cbox\TelemetryInsights\Notify\WebhookChannel;

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | Off means the package registers nothing that runs: no commands, no
    | schedule, no dashboard pages. The tables stay where they are.
    |
    */

    'enabled' => (bool) env('TELEMETRY_INSIGHTS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Scope
    |--------------------------------------------------------------------------
    |
    | Which slice of the fleet the scheduled work reads, and how far back each
    | pass looks. The window wants to be comfortably longer than the interval
    | the scan runs on, so a slow ingest never drops an error between passes.
    |
    */

    'scope' => [
        'service' => env('TELEMETRY_INSIGHTS_SERVICE', ''),
        'environment' => env('TELEMETRY_INSIGHTS_ENVIRONMENT', ''),
        'window' => env('TELEMETRY_INSIGHTS_WINDOW', '15m'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Correlation
    |--------------------------------------------------------------------------
    |
    | How a burst of unrelated errors is folded back into one incident.
    |
    | "burst_seconds" is how close together two groups must start to count as
    | the same event; "min_groups" is how many it takes before a burst is an
    | incident at all rather than just a bad minute. "shared_ratio" is the
    | share of the burst that must touch the same failing dependency before it
    | is named as the cause — below this we report the incident with no cause
    | rather than guess.
    |
    */

    'correlation' => [
        'burst_seconds' => (int) env('TELEMETRY_INSIGHTS_BURST_SECONDS', 120),
        'min_groups' => (int) env('TELEMETRY_INSIGHTS_MIN_GROUPS', 3),
        'shared_ratio' => (float) env('TELEMETRY_INSIGHTS_SHARED_RATIO', 0.6),
        // Each probe is one trace fetch, so this bounds the cost of a pass.
        'max_trace_probes' => (int) env('TELEMETRY_INSIGHTS_MAX_PROBES', 20),
        'sample_limit' => (int) env('TELEMETRY_INSIGHTS_SAMPLE_LIMIT', 500),
        'change_window_minutes' => (int) env('TELEMETRY_INSIGHTS_CHANGE_WINDOW', 120),
        'signal_pad_seconds' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Digest
    |--------------------------------------------------------------------------
    |
    | Thresholds for "worth mentioning". A route slower than slow_route_ms at
    | p95, a statement averaging slow_query_ms, or one called
    | repeated_query_calls times in the window (fast, but in a loop).
    |
    */

    'digest' => [
        'limit' => (int) env('TELEMETRY_INSIGHTS_DIGEST_LIMIT', 5),
        'slow_route_ms' => (float) env('TELEMETRY_INSIGHTS_SLOW_ROUTE_MS', 1000),
        'slow_query_ms' => (float) env('TELEMETRY_INSIGHTS_SLOW_QUERY_MS', 100),
        'repeated_query_calls' => (int) env('TELEMETRY_INSIGHTS_REPEATED_QUERY_CALLS', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduled tasks
    |--------------------------------------------------------------------------
    |
    | Read from the gauges cboxdk/laravel-telemetry publishes about the
    | schedule (`instrument.schedule_monitoring`). Each pass of the scan turns
    | what is wrong into issues, and resolves them when the condition clears:
    |
    | - the scheduler stopped: schedule:run has not started for
    |   scheduler_stopped_after_seconds;
    | - a task missed: it was due more than missed_after_seconds before it
    |   last started (or was skipped), and schedule:run listed it within
    |   listed_within_seconds — so a task you deleted is not "missed";
    | - a task overran: a run started more than overrun_after_seconds ago
    |   and has not finished (a worker that died mid-run looks the same);
    | - a task is failing: it failed failure_threshold times in a row.
    |
    | Override any of the four per task under "tasks", keyed by the task's
    | name or a `*` pattern; an exact name wins over a pattern. Set
    | 'enabled' => false there to stop watching a task altogether.
    |
    |   'tasks' => [
    |       'reports:nightly' => ['overrun_after_seconds' => 4 * 3600],
    |       'sync:*' => ['failure_threshold' => 3],
    |   ],
    |
    */

    'scheduled_tasks' => [
        'enabled' => (bool) env('TELEMETRY_INSIGHTS_SCHEDULED_TASKS', true),
        'scheduler_stopped_after_seconds' => (int) env('TELEMETRY_INSIGHTS_SCHEDULER_STOPPED_AFTER', 180),
        'missed_after_seconds' => (int) env('TELEMETRY_INSIGHTS_TASK_MISSED_AFTER', 300),
        'listed_within_seconds' => 600,
        'overrun_after_seconds' => (int) env('TELEMETRY_INSIGHTS_TASK_OVERRUN_AFTER', 3600),
        'failure_threshold' => (int) env('TELEMETRY_INSIGHTS_TASK_FAILURE_THRESHOLD', 1),
        // Counting a streak costs one read per failing task; this bounds it.
        'max_failure_probes' => 20,
        'tasks' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Escalation (archive until escalating)
    |--------------------------------------------------------------------------
    |
    | An archived error group stays quiet until its hourly volume goes past
    | what it did in the baseline_days before it was archived:
    |
    | - over the SPIKE limit (95th-percentile hour × spike_factor) for
    |   sustained_hours running — a real, lasting rise; or
    | - over the BURST limit (worst hour × burst_factor) in any one hour —
    |   something it has never done. A nightly job's usual burst sets the
    |   peak, so its next one does not reopen anything.
    |
    | Neither limit goes below min_volume. Learning reads up to sample_limit
    | exception records once, when the issue is archived.
    |
    */

    'escalation' => [
        'baseline_days' => 7,
        'spike_factor' => 3.0,
        'burst_factor' => 1.5,
        'sustained_hours' => 2,
        'min_volume' => (int) env('TELEMETRY_INSIGHTS_ESCALATION_MIN', 10),
        'sample_limit' => 10000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Stale issues
    |--------------------------------------------------------------------------
    |
    | Open error groups with no occurrence for this many days (7, 14 or 30
    | are sensible) are resolved by the scan and recorded as automatic. A
    | later occurrence reopens one as a regression.
    |
    */

    'stale' => [
        'enabled' => (bool) env('TELEMETRY_INSIGHTS_STALE_RESOLVE', true),
        'resolve_after_days' => (int) env('TELEMETRY_INSIGHTS_STALE_DAYS', 14),
    ],

    /*
    |--------------------------------------------------------------------------
    | Profiling (directives)
    |--------------------------------------------------------------------------
    |
    | Time-limited directives on the monitored apps: "keep every trace of
    | this route (and CPU-profile it) for 15 minutes". Needs a
    | cboxdk/laravel-telemetry with directives in the app being watched.
    |
    | Reaching it: an app listed under "targets" gets the signed HTTP
    | endpoint at its url, with the secret it has as
    | TELEMETRY_DIRECTIVES_SECRET. With no target for a service, and
    | laravel-telemetry running in THIS app, directives go straight into its
    | store.
    |
    |   'targets' => [
    |       'shop' => ['url' => env('SHOP_URL'), 'secret' => env('SHOP_DIRECTIVES_SECRET')],
    |   ],
    |
    | The rotation profiles the top "routes" routes by impact ("total_time":
    | seconds spent in the route over "lookback"; or "p95_rate": p95 ×
    | requests per second) that were not profiled in the last
    | "cooldown_hours", for "minutes" each. COST: every request on those
    | routes is kept and profiled for those minutes. Off by default.
    |
    */

    'profiling' => [
        'targets' => [],
        'timeout' => 5,

        'rotation' => [
            'enabled' => (bool) env('TELEMETRY_INSIGHTS_PROFILING', false),
            'routes' => (int) env('TELEMETRY_INSIGHTS_PROFILING_ROUTES', 3),
            'minutes' => (int) env('TELEMETRY_INSIGHTS_PROFILING_MINUTES', 15),
            'profile' => true,
            'impact' => env('TELEMETRY_INSIGHTS_PROFILING_IMPACT', 'total_time'),
            'lookback' => '24h',
            'cooldown_hours' => (int) env('TELEMETRY_INSIGHTS_PROFILING_COOLDOWN', 24),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Agent tools (MCP)
    |--------------------------------------------------------------------------
    |
    | The issue ledger as tools on the dashboard's MCP server
    | (`php artisan mcp:start telemetry-ui`, or its web endpoint): list and
    | show issues, and resolve, ignore or reopen one. Every change is written
    | to the issue's history as made via agent, with the user if the
    | transport has one.
    |
    | Changing an issue needs the same `manageTelemetryUi` ability the
    | dashboard checks. "writes" false registers the read tools only.
    |
    */

    'mcp' => [
        'enabled' => (bool) env('TELEMETRY_INSIGHTS_MCP', true),
        'writes' => (bool) env('TELEMETRY_INSIGHTS_MCP_WRITES', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Channels are resolved by name from the container, so adding your own is
    | a class implementing NotifiesChannel plus a line here. The default set
    | is what a finding reaches when a rule names no channels of its own.
    |
    */

    'notify' => [
        'default' => ['log'],

        'channels' => [
            'log' => LogChannel::class,
            'slack' => SlackChannel::class,
            'webhook' => WebhookChannel::class,
        ],

        'log' => [
            'level' => env('TELEMETRY_INSIGHTS_LOG_LEVEL', 'warning'),
        ],

        'slack' => [
            'webhook_url' => env('TELEMETRY_INSIGHTS_SLACK_WEBHOOK'),
            'timeout' => 5,
        ],

        'webhook' => [
            'url' => env('TELEMETRY_INSIGHTS_WEBHOOK_URL'),
            'timeout' => 5,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | What the scan announces
    |--------------------------------------------------------------------------
    |
    | A new issue and a regression are the two things worth interrupting
    | someone for without a rule being configured, and a scheduled task that
    | stopped running is a third. Turn any off to keep the scan recording
    | them silently.
    |
    */

    'announce' => [
        'new_issues' => (bool) env('TELEMETRY_INSIGHTS_ANNOUNCE_NEW', true),
        'regressions' => (bool) env('TELEMETRY_INSIGHTS_ANNOUNCE_REGRESSIONS', true),
        'incidents' => (bool) env('TELEMETRY_INSIGHTS_ANNOUNCE_INCIDENTS', true),
        'scheduled_tasks' => (bool) env('TELEMETRY_INSIGHTS_ANNOUNCE_SCHEDULED_TASKS', true),
        // A scheduled-task issue that cleared on its own, when the problem
        // itself was announced.
        'recoveries' => (bool) env('TELEMETRY_INSIGHTS_ANNOUNCE_RECOVERIES', true),
        'escalations' => (bool) env('TELEMETRY_INSIGHTS_ANNOUNCE_ESCALATIONS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Schedule
    |--------------------------------------------------------------------------
    |
    | Registered on the host's scheduler when "enabled" is true. Set a cron
    | expression to null to leave that job to your own scheduling.
    |
    */

    'schedule' => [
        'scan' => env('TELEMETRY_INSIGHTS_SCAN_CRON', '*/5 * * * *'),
        'alerts' => env('TELEMETRY_INSIGHTS_ALERTS_CRON', '*/5 * * * *'),
        'digest' => env('TELEMETRY_INSIGHTS_DIGEST_CRON', '0 8 * * 1'),
        // Only registered while profiling.rotation.enabled is true.
        'profiling' => env('TELEMETRY_INSIGHTS_PROFILING_CRON', '0 */4 * * *'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tables
    |--------------------------------------------------------------------------
    */

    'tables' => [
        'issues' => 'telemetry_issues',
        'incidents' => 'telemetry_incidents',
        'alert_rules' => 'telemetry_alert_rules',
        'alert_events' => 'telemetry_alert_events',
        'issue_events' => 'telemetry_issue_events',
        'profiling_runs' => 'telemetry_profiling_runs',
    ],

];
