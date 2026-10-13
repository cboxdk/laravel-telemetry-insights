<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;

/**
 * Booting must not build the host's schedule.
 *
 * Resolving Schedule runs the host's whole `schedule()` method, and a host is
 * entitled to build its schedule from state — one asks the jobs table whether
 * a job is already queued before scheduling it. Forcing that from a booted()
 * hook ran it in every console command, `package:discover` during
 * `composer install` included, where there is no database yet: installing this
 * package broke the install that was installing it.
 */
it('does not resolve the scheduler while booting', function (): void {
    expect(app()->resolved(Schedule::class))->toBeFalse();
});

it('registers its jobs when something else resolves the scheduler', function (): void {
    $schedule = app(Schedule::class);

    $commands = collect($schedule->events())
        ->map(fn ($event): string => (string) $event->command)
        ->filter(fn (string $command): bool => str_contains($command, 'telemetry-insights:'))
        ->values();

    expect($commands)->toHaveCount(3);
});

it('leaves a job out when its cron is set to null', function (): void {
    config()->set('telemetry-insights.schedule.digest', null);

    $schedule = app(Schedule::class);

    $digest = collect($schedule->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'telemetry-insights:digest'));

    expect($digest)->toBeEmpty();
});

it('schedules the profiling rotation only when it is turned on', function (): void {
    $profiling = static fn (): int => collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'telemetry-insights:profiling'))
        ->count();

    expect($profiling())->toBe(0);

    config()->set('telemetry-insights.profiling.rotation.enabled', true);
    app()->forgetInstance(Schedule::class);

    expect($profiling())->toBe(1);
});
