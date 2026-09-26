<?php

declare(strict_types=1);

use Cbox\TelemetryInsights\TelemetryInsightsServiceProvider;

/**
 * The boundaries that keep this package cheap to move.
 *
 * The plan is that the dashboard's read layer — contracts, drivers, query
 * IR — eventually becomes a package of its own, at which point this one
 * should depend on that rather than on a package that also ships a React
 * app. That is only a rename if the domain never reached into the
 * dashboard's presentation layer, so the build fails when it does.
 */

/**
 * Every `use` statement in a source file.
 *
 * @return list<string>
 */
function importsOf(string $file): array
{
    $source = (string) file_get_contents($file);
    preg_match_all('/^use\s+([^\s;(]+)/m', $source, $matches);

    return $matches[1];
}

/**
 * @return list<string> every PHP file under src/, relative to it
 */
function sourceFiles(): array
{
    $root = dirname(__DIR__, 2).'/src';
    $files = [];

    /** @var iterable<SplFileInfo> $iterator */
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = str_replace($root.'/', '', $file->getPathname());
        }
    }

    sort($files);

    return $files;
}

it('keeps the domain clear of the dashboard presentation layer', function (): void {
    // RequestScope lives under the dashboard's Http namespace but is a plain
    // value object with a plain constructor — the query layer's scope
    // primitive, used here from cron jobs with no request in sight. It is
    // the one exception, and it moves with the read layer.
    $allowed = ['Cbox\TelemetryUi\Http\Api\RequestScope'];
    $offences = [];

    foreach (sourceFiles() as $file) {
        if (str_starts_with($file, 'Ui/')) {
            continue;
        }

        foreach (importsOf(dirname(__DIR__, 2).'/src/'.$file) as $import) {
            $isPresentation = str_starts_with($import, 'Cbox\TelemetryUi\Panels')
                || str_starts_with($import, 'Cbox\TelemetryUi\Http')
                || str_starts_with($import, 'Cbox\TelemetryUi\Facades');

            if ($isPresentation && ! in_array($import, $allowed, true)) {
                $offences[] = $file.' imports '.$import;
            }
        }
    }

    expect($offences)->toBe([]);
});

it('confines everything that knows the dashboard exists to one folder', function (): void {
    $outside = [];

    foreach (sourceFiles() as $file) {
        if (str_starts_with($file, 'Ui/')) {
            continue;
        }

        $source = (string) file_get_contents(dirname(__DIR__, 2).'/src/'.$file);

        // The provider is allowed to call the registrar; that is how the
        // soft half is wired up.
        if ($file === 'TelemetryInsightsServiceProvider.php') {
            continue;
        }

        if (str_contains($source, 'telemetry-ui.enabled') || str_contains($source, 'TelemetryUiManager')) {
            $outside[] = $file;
        }
    }

    expect($outside)->toBe([]);
});

it('registers at boot without touching a backend or the database', function (): void {
    // A provider that queries at boot makes every artisan command slower and
    // every deploy riskier. Registration is class-strings and closures only.
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/src/'.'TelemetryInsightsServiceProvider.php');

    foreach (['::query(', 'DB::', 'Http::', '->get(\'http', 'Schema::'] as $forbidden) {
        expect($source)->not->toContain($forbidden);
    }

    // And the real thing: booting the app resolved the provider already.
    expect(app()->getProviders(TelemetryInsightsServiceProvider::class))->not->toBeEmpty();
});

it('declares strict types everywhere', function (): void {
    $missing = [];

    foreach (sourceFiles() as $file) {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/src/'.$file);

        if (! str_contains($source, 'declare(strict_types=1);')) {
            $missing[] = $file;
        }
    }

    expect($missing)->toBe([]);
});

it('leaves the classes a host needs to extend or fake unsealed', function (): void {
    // Sealed by default; this list is the API surface a consumer may
    // subclass, decorate or mock, and each entry says why.
    $open = [
        'Alerts/AlertEvaluator.php' => 'hosts decorate firing (dedupe, business hours)',
        'Alerts/RuleMeasurer.php' => 'hosts add their own measurable rule types',
        'Digest/DigestBuilder.php' => 'hosts contribute their own findings',
        'Issues/IssueLedger.php' => 'hosts override how issues are recorded',
        'Issues/IncidentRecorder.php' => 'hosts override persistence',
        'Models/AlertEvent.php' => 'Eloquent models are extended by hosts',
        'Models/AlertRule.php' => 'Eloquent models are extended by hosts',
        'Models/Incident.php' => 'Eloquent models are extended by hosts',
        'Models/Issue.php' => 'Eloquent models are extended by hosts',
        'Notify/Notifier.php' => 'FakeNotifier extends it; hosts may too',
        'Scan/Scanner.php' => 'hosts wrap the pass',
        'TelemetryInsightsServiceProvider.php' => 'providers are never final',
        'Ui/Panels/IncidentsTable.php' => 'panels are extended to re-scope them',
        'Ui/Panels/IssuesTable.php' => 'panels are extended to re-scope them',
    ];

    $wronglySealed = [];
    $wronglyOpen = [];

    foreach (sourceFiles() as $file) {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/src/'.$file);

        // Enums and interfaces are neither.
        if (preg_match('/^(enum|interface|trait) /m', $source) === 1) {
            continue;
        }

        $isFinal = str_contains($source, 'final class') || str_contains($source, 'final readonly class');
        $shouldBeOpen = array_key_exists($file, $open);

        if ($shouldBeOpen && $isFinal) {
            $wronglySealed[] = $file;
        }

        if (! $shouldBeOpen && ! $isFinal) {
            $wronglyOpen[] = $file;
        }
    }

    expect($wronglySealed)->toBe([])
        ->and($wronglyOpen)->toBe([]);
});
