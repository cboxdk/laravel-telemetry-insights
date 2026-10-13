<?php

declare(strict_types=1);

use Cbox\TelemetryInsights\Alerts\AlertType;
use Cbox\TelemetryInsights\Digest\DigestBuilder;
use Cbox\TelemetryInsights\Digest\FindingKind;
use Cbox\TelemetryInsights\Issues\ActionSource;
use Cbox\TelemetryInsights\Issues\Attribution;
use Cbox\TelemetryInsights\Issues\ChangeKind;
use Cbox\TelemetryInsights\Issues\IssueAction;
use Cbox\TelemetryInsights\Issues\IssueActions;
use Cbox\TelemetryInsights\Issues\IssueChange;
use Cbox\TelemetryInsights\Issues\IssueKind;
use Cbox\TelemetryInsights\Issues\IssueStatus;
use Cbox\TelemetryInsights\Issues\ScheduleLedger;
use Cbox\TelemetryInsights\Models\AlertEvent;
use Cbox\TelemetryInsights\Models\AlertRule;
use Cbox\TelemetryInsights\Models\Issue;
use Cbox\TelemetryInsights\Scan\Scanner;
use Cbox\TelemetryInsights\Schedule\ScheduleProblem;
use Cbox\TelemetryUi\Http\Api\RequestScope;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Scheduled-task issues: the runs that never happened, read off the gauges
 * cboxdk/laravel-telemetry publishes about the schedule. Conditions, not
 * occurrences — so they open while the condition holds and resolve
 * themselves when it clears.
 */

/**
 * The scheduler's gauges as Prometheus would answer them, keyed by metric.
 * Mutable, for the same reason as backendState(): Http::fake() merges, and
 * these tests change the world between passes.
 *
 * @param  array<string, list<array{labels: array<string, string>, value: float}>>|null  $set
 * @return array<string, list<array{labels: array<string, string>, value: float}>>
 */
function scheduleGauges(?array $set = null): array
{
    static $state = [];

    if ($set !== null) {
        $state = $set;
    }

    return $state;
}

/**
 * Stub Prometheus with the gauges. A failure-streak probe (it names the
 * task) reads `streak`; the window read reads `failed`.
 *
 * @param  array<string, list<array{labels: array<string, string>, value: float}>>  $gauges
 */
function fakeSchedule(array $gauges): void
{
    scheduleGauges($gauges);

    Http::fake([
        'prometheus.test:9090/api/v1/query*' => static function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $params);
            $query = is_string($params['query'] ?? null) ? $params['query'] : '';
            $gauges = scheduleGauges();

            $pick = static function (string $key) use ($gauges, $query): array {
                $series = $gauges[$key] ?? [];

                return is_callable($series) ? $series($query) : $series;
            };

            // Background runs have their own counter, which also carries
            // the exit code.
            if (str_contains($query, 'schedule_background_tasks_failed_total')) {
                return Http::response(promVector($pick(str_contains($query, 'task="') ? 'bg_streak' : 'bg_failed')));
            }

            if (str_contains($query, 'schedule_tasks_failed_total')) {
                return Http::response(promVector($pick(str_contains($query, 'task="') ? 'streak' : 'failed')));
            }

            foreach ($gauges as $metric => $series) {
                if (str_starts_with($metric, 'schedule_') && is_array($series) && str_contains($query, $metric)) {
                    return Http::response(promVector($series));
                }
            }

            return Http::response(promVector([]));
        },
    ]);
}

/**
 * @return array{labels: array<string, string>, value: float}
 */
function series(float $value, ?string $task = 'reports:nightly', string $service = 'shop'): array
{
    $labels = ['service_name' => $service, 'deployment_environment_name' => 'production'];

    if ($task !== null) {
        $labels['task'] = $task;
    }

    return ['labels' => $labels, 'value' => $value];
}

/**
 * A healthy scheduler at $now, with one task in whatever state the
 * overrides say.
 *
 * @param  array<string, list<array{labels: array<string, string>, value: float}>>  $overrides
 * @return array<string, list<array{labels: array<string, string>, value: float}>>
 */
function schedule(int $now, array $overrides = []): array
{
    return [
        'schedule_run_last_seen_seconds' => [series($now - 20, null)],
        'schedule_task_previous_due_seconds' => [series($now - 60)],
        'schedule_task_listed_seconds' => [series($now - 20)],
        'schedule_task_last_seen_seconds' => [series($now - 55)],
        'schedule_task_running_since_seconds' => [series(0)],
        'schedule_task_last_succeeded_seconds' => [series($now - 50)],
        ...$overrides,
    ];
}

/** Due an hour ago, last ran two hours ago: 60 minutes behind. */
function missedSchedule(int $now): array
{
    return schedule($now, [
        'schedule_task_previous_due_seconds' => [series($now - 3600)],
        'schedule_task_last_seen_seconds' => [series($now - 7200)],
    ]);
}

/**
 * @return list<IssueChange>
 */
function recordSchedule(int $now): array
{
    return app(ScheduleLedger::class)->record(new RequestScope(period: '15m'), $now);
}

/**
 * @param  list<IssueChange>  $changes
 * @return list<string>
 */
function changeKinds(array $changes): array
{
    return array_map(static fn (IssueChange $c): string => $c->kind->value.':'.$c->issue->kind()->value, $changes);
}

it('opens an issue for a task that missed its slot, and stays quiet while it is still missing', function (): void {
    $now = time();
    fakeSchedule(missedSchedule($now));

    expect(changeKinds(recordSchedule($now)))->toBe(['new:scheduled_task_missed']);

    $issue = Issue::query()->sole();
    expect($issue->kind())->toBe(IssueKind::TaskMissed)
        ->and($issue->subject)->toBe('reports:nightly')
        ->and($issue->service)->toBe('shop')
        ->and($issue->environment)->toBe('production')
        ->and($issue->title())->toBe('Scheduled task missed: reports:nightly')
        ->and($issue->message)->toContain('1h behind')
        ->and($issue->fingerprint)->toBe(ScheduleProblem::fingerprintFor(IssueKind::TaskMissed, 'shop', 'production', 'reports:nightly'))
        ->and($issue->events()->sole()->action)->toBe(IssueAction::Opened);

    expect(changeKinds(recordSchedule($now + 60)))->toBe(['recurring:scheduled_task_missed'])
        ->and(Issue::query()->count())->toBe(1);
});

it('resolves a missed task when it runs again, attributed to the scan', function (): void {
    $now = time();
    fakeSchedule(missedSchedule($now));
    recordSchedule($now);

    // The task ran: last_seen caught up with the due time.
    fakeSchedule(schedule($now));

    expect(changeKinds(recordSchedule($now + 60)))->toBe(['recovered:scheduled_task_missed']);

    $issue = Issue::query()->sole();
    $event = $issue->events()->get()->last();

    expect($issue->status)->toBe(IssueStatus::Resolved)
        ->and($issue->resolved_by)->toBe(Attribution::SCAN_ACTOR)
        ->and($event?->action)->toBe(IssueAction::Resolved)
        ->and($event?->source)->toBe(ActionSource::Scan)
        ->and($event?->comment)->toContain('ran again');
});

it('calls a miss after an automatic recovery a regression', function (): void {
    $now = time();
    fakeSchedule(missedSchedule($now));
    recordSchedule($now);
    fakeSchedule(schedule($now));
    recordSchedule($now + 60);

    fakeSchedule(missedSchedule($now + 7200));

    expect(changeKinds(recordSchedule($now + 7200)))->toBe(['regression:scheduled_task_missed'])
        ->and(Issue::query()->sole()->status)->toBe(IssueStatus::Open);
});

it('does not contradict a person who resolved a task that is still behind — until a new episode starts', function (): void {
    $now = time();
    fakeSchedule(missedSchedule($now));
    recordSchedule($now);

    Carbon::setTestNow(Carbon::createFromTimestamp($now + 30));
    app(IssueActions::class)->resolve(Issue::query()->sole(), 'sylvester');
    Carbon::setTestNow();

    // Same episode: still behind since the same last run.
    expect(changeKinds(recordSchedule($now + 60)))->toBe(['recurring:scheduled_task_missed'])
        ->and(Issue::query()->sole()->status)->toBe(IssueStatus::Resolved);

    // It ran after the resolve, then missed again: a new episode.
    fakeSchedule(schedule($now + 7200, [
        'schedule_task_previous_due_seconds' => [series($now + 7200 - 600)],
        'schedule_task_last_seen_seconds' => [series($now + 600)],
    ]));

    expect(changeKinds(recordSchedule($now + 7200)))->toBe(['regression:scheduled_task_missed']);
});

it('does not call a task missed once it has left the schedule', function (): void {
    $now = time();
    fakeSchedule(schedule($now, [
        'schedule_task_previous_due_seconds' => [series($now - 3600)],
        'schedule_task_last_seen_seconds' => [series($now - 7200)],
        // Not listed by schedule:run for an hour: deleted from the schedule.
        'schedule_task_listed_seconds' => [series($now - 3600)],
    ]));

    expect(recordSchedule($now))->toBe([]);
});

it('reports a stopped scheduler, and leaves its missed tasks alone until it is back', function (): void {
    $now = time();
    fakeSchedule(missedSchedule($now));
    recordSchedule($now);

    // schedule:run stopped ten minutes ago: due times are stale, so the
    // missed task is neither re-confirmed nor resolved.
    fakeSchedule(schedule($now + 600, [
        'schedule_run_last_seen_seconds' => [series($now, null)],
        'schedule_task_previous_due_seconds' => [series($now - 3600)],
        'schedule_task_last_seen_seconds' => [series($now - 7200)],
        'schedule_task_listed_seconds' => [series($now)],
    ]));

    expect(changeKinds(recordSchedule($now + 600)))->toBe(['new:scheduler_stopped']);

    $stopped = Issue::query()->where('kind', IssueKind::SchedulerStopped->value)->sole();
    expect($stopped->title())->toBe('Scheduler stopped on shop · production')
        ->and($stopped->subject)->toBeNull()
        ->and(Issue::query()->where('kind', IssueKind::TaskMissed->value)->sole()->status)->toBe(IssueStatus::Open);

    // Back, and the task caught up.
    fakeSchedule(schedule($now + 900));

    expect(changeKinds(recordSchedule($now + 900)))->toEqualCanonicalizing([
        'recovered:scheduled_task_missed',
        'recovered:scheduler_stopped',
    ]);
});

it('flags an overrunning task, honouring a per-task allowance', function (): void {
    config()->set('telemetry-insights.scheduled_tasks.tasks', ['exports:*' => ['overrun_after_seconds' => 4 * 3600]]);

    $now = time();
    fakeSchedule(schedule($now, [
        'schedule_task_running_since_seconds' => [
            series($now - 2 * 3600, 'reports:nightly'),
            series($now - 2 * 3600, 'exports:warehouse'),
        ],
    ]));

    $changes = recordSchedule($now);

    expect(changeKinds($changes))->toBe(['new:scheduled_task_overrunning'])
        ->and($changes[0]->issue->subject)->toBe('reports:nightly')
        ->and($changes[0]->issue->message)->toContain('running for 2h');

    // The run finished.
    fakeSchedule(schedule($now + 60));

    expect(changeKinds(recordSchedule($now + 60)))->toBe(['recovered:scheduled_task_overrunning']);
});

it('stops watching a task whose override disables it', function (): void {
    config()->set('telemetry-insights.scheduled_tasks.tasks', ['reports:nightly' => ['enabled' => false]]);

    $now = time();
    fakeSchedule(missedSchedule($now));

    expect(recordSchedule($now))->toBe([]);
});

it('waits for N failures in a row before a failing task is an issue', function (): void {
    config()->set('telemetry-insights.scheduled_tasks.failure_threshold', 3);

    $now = time();
    $failing = static fn (float $streak): array => schedule($now, [
        'schedule_task_last_succeeded_seconds' => [series($now - 3 * 86_400)],
        'failed' => [series(1)],
        'streak' => [series($streak)],
    ]);

    fakeSchedule($failing(2));
    expect(recordSchedule($now))->toBe([]);

    fakeSchedule($failing(3));
    $changes = recordSchedule($now);

    expect(changeKinds($changes))->toBe(['new:scheduled_task_failing'])
        ->and($changes[0]->count)->toBe(3)
        ->and($changes[0]->issue->message)->toContain('failed 3 times in a row');
});

it('keeps a failing task open through a quiet window, and resolves it on a success', function (): void {
    $now = time();
    fakeSchedule(schedule($now, [
        'schedule_task_last_succeeded_seconds' => [series($now - 86_400)],
        'failed' => [series(1)],
        'streak' => [series(1)],
    ]));
    recordSchedule($now);

    // The next pass sees no failure in its window, but no success either:
    // a nightly task that failed at 02:00 is still failing at 09:00.
    fakeSchedule(schedule($now + 300, [
        'schedule_task_last_succeeded_seconds' => [series($now - 86_400)],
    ]));
    expect(recordSchedule($now + 300))->toBe([]);

    // It succeeded.
    fakeSchedule(schedule($now + 600, [
        'schedule_task_last_succeeded_seconds' => [series($now + 550)],
    ]));
    expect(changeKinds(recordSchedule($now + 600)))->toBe(['recovered:scheduled_task_failing']);
});

it('changes nothing when Prometheus will not answer', function (): void {
    $now = time();
    fakeSchedule(missedSchedule($now));
    recordSchedule($now);

    // Http::fake() merges and the first stub wins, so start a fresh factory.
    Http::swap(new Factory);
    Http::fake(['prometheus.test:9090/*' => Http::response('down', 502)]);

    expect(recordSchedule($now + 60))->toBe([])
        ->and(Issue::query()->sole()->status)->toBe(IssueStatus::Open);
});

it('does nothing when scheduled-task monitoring is off', function (): void {
    config()->set('telemetry-insights.scheduled_tasks.enabled', false);

    $now = time();
    fakeSchedule(missedSchedule($now));

    expect(recordSchedule($now))->toBe([]);
});

it('announces through the scan, fires the scheduled-task rule, and announces the recovery', function (): void {
    $rule = new AlertRule;
    $rule->forceFill([
        'name' => 'Schedule watch',
        'type' => AlertType::ScheduledTask,
        'window_minutes' => 5,
        'cooldown_minutes' => 0,
        'enabled' => true,
    ])->save();

    fakeBackends([]);
    fakeSchedule(missedSchedule(time()));

    $notifier = $this->fakeNotifications();
    $result = app(Scanner::class)->scan(new RequestScope(period: '15m'));

    expect($result->scheduleProblems)->toBe(1)
        ->and($result->scheduleNews)->toBe(1)
        ->and($result->summary())->toContain('1 schedule problem(s)');

    $notifier->assertSent('Scheduled task missed: reports:nightly');
    expect(AlertEvent::query()->sole()->summary)->toBe('Scheduled task missed: reports:nightly');

    // Quiet while it holds.
    $notifier = $this->fakeNotifications();
    app(Scanner::class)->scan(new RequestScope(period: '15m'));
    $notifier->assertNothingSent();

    // Recovered.
    fakeSchedule(schedule(time()));
    $notifier = $this->fakeNotifications();
    $result = app(Scanner::class)->scan(new RequestScope(period: '15m'));

    expect($result->recovered)->toBe(1);
    $notifier->assertSent('Recovered: Scheduled task missed: reports:nightly');
});

it('puts the current schedule problems in the digest, minus the ignored ones', function (): void {
    fakeBackends([]);
    $now = time();
    fakeSchedule(schedule($now, [
        'schedule_task_previous_due_seconds' => [series($now - 3600), series($now - 3600, 'cache:prune')],
        'schedule_task_last_seen_seconds' => [series($now - 7200), series($now - 7200, 'cache:prune')],
        'schedule_task_listed_seconds' => [series($now - 20), series($now - 20, 'cache:prune')],
    ]));

    recordSchedule($now);
    $pruned = Issue::query()->where('subject', 'cache:prune')->sole();
    app(IssueActions::class)->ignore($pruned);

    $digest = app(DigestBuilder::class)->build(new RequestScope(period: '24h'));
    $findings = $digest->ofKind(FindingKind::ScheduledTask);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->title)->toBe('Scheduled task missed: reports:nightly')
        ->and($findings[0]->facts['Behind by'])->toBe('1h')
        ->and($digest->toMarkdown())->toContain('[Scheduled task] Scheduled task missed: reports:nightly');
});

it('describes the change kinds it reports', function (): void {
    expect(ChangeKind::Recovered->isNews())->toBeFalse()
        ->and(ChangeKind::New->isNews())->toBeTrue()
        ->and(ChangeKind::Regression->isNews())->toBeTrue()
        ->and(IssueKind::scheduled())->not->toContain(IssueKind::Exception);
});

it('does not call a task failing when it has succeeded since its failures', function (): void {
    $now = time();
    // A week-long window saw a failure, but the since-success read says none.
    fakeSchedule(schedule($now, [
        'schedule_task_last_succeeded_seconds' => [series($now - 3600)],
        'failed' => [series(1)],
        'streak' => [],
    ]));

    expect(app(ScheduleLedger::class)->record(new RequestScope(period: '7d'), $now))->toBe([]);
});

it('counts background runs in a failure streak', function (): void {
    config()->set('telemetry-insights.scheduled_tasks.failure_threshold', 3);

    $now = time();
    fakeSchedule(schedule($now, [
        'schedule_task_last_succeeded_seconds' => [series($now - 86_400)],
        'bg_failed' => [series(2)],
        'streak' => [series(1)],
        'bg_streak' => [series(2)],
    ]));

    $changes = recordSchedule($now);

    expect(changeKinds($changes))->toBe(['new:scheduled_task_failing'])
        ->and($changes[0]->count)->toBe(3);
});

it('reopens a failing task a person resolved when it fails again after the resolve', function (): void {
    $now = time();
    $failing = static fn (bool $failedSinceResolve): array => schedule($now, [
        'schedule_task_last_succeeded_seconds' => [series($now - 86_400)],
        'failed' => [series(1)],
        // The since-resolve read is the short one (a minute); the streak
        // read reaches back to the last success.
        'streak' => static fn (string $query): array => str_contains($query, '[60s]')
            ? ($failedSinceResolve ? [series(1)] : [])
            : [series(2)],
    ]);

    fakeSchedule($failing(false));
    recordSchedule($now);
    app(IssueActions::class)->resolve(Issue::query()->sole(), 'sylvester');

    expect(changeKinds(recordSchedule($now + 30)))->toBe(['recurring:scheduled_task_failing'])
        ->and(Issue::query()->sole()->status)->toBe(IssueStatus::Resolved);

    fakeSchedule($failing(true));

    expect(changeKinds(recordSchedule($now + 30)))->toBe(['regression:scheduled_task_failing'])
        ->and(Issue::query()->sole()->status)->toBe(IssueStatus::Open);
});

it('never resolves a snoozed scheduled-task issue, until the snooze runs out', function (): void {
    $now = time();
    fakeSchedule(missedSchedule($now));
    recordSchedule($now);
    app(IssueActions::class)->snooze(Issue::query()->sole(), Carbon::now()->addDay());

    fakeSchedule(schedule($now));

    expect(recordSchedule($now + 60))->toBe([])
        ->and(Issue::query()->sole()->status)->toBe(IssueStatus::Snoozed);

    Issue::query()->sole()->forceFill(['snoozed_until' => Carbon::now()->subMinute()])->save();

    expect(changeKinds(recordSchedule($now + 120)))->toBe(['recovered:scheduled_task_missed']);
});
