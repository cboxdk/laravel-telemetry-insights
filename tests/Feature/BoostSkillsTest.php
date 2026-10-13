<?php

declare(strict_types=1);

use Cbox\TelemetryInsights\Issues\IssueKind;
use Cbox\TelemetryInsights\Issues\IssueStatus;
use Cbox\TelemetryUi\TelemetryUiManager;
use Laravel\Mcp\Server\Tool;
use Symfony\Component\Yaml\Yaml;

/*
 * Laravel Boost installs a package's skills from
 * resources/boost/skills/{name}/SKILL.md. A skill whose frontmatter does not
 * parse, or that has no description, is skipped without a word, and a skill
 * that names a tool, argument or status the ledger does not have sends an
 * agent looking for something that is not there. Both are pinned here.
 */

function insightsPackageRoot(): string
{
    return dirname(__DIR__, 2);
}

/**
 * @return array<string, string> skill directory name => SKILL.md path
 */
function insightsBoostSkills(): array
{
    $skills = [];

    foreach (glob(insightsPackageRoot().'/resources/boost/skills/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $skills[basename($dir)] = $dir.'/SKILL.md';
    }

    return $skills;
}

/**
 * Tool name => its argument names, for every tool this package puts on the
 * dashboard's MCP server.
 *
 * @return array<string, list<string>>
 */
function insightsMcpTools(): array
{
    $tools = [];

    foreach (app(TelemetryUiManager::class)->mcpTools() as $class) {
        if (! str_starts_with($class, 'Cbox\\TelemetryInsights\\')) {
            continue;
        }

        /** @var Tool $tool */
        $tool = app($class);
        $tools[$tool->name()] = array_keys((array) $tool->toArray()['inputSchema']['properties']);
    }

    return $tools;
}

it('ships skills Boost can parse', function () {
    expect(insightsBoostSkills())->not->toBeEmpty();

    foreach (insightsBoostSkills() as $dir => $file) {
        expect($file)->toBeFile();

        // The same split Boost's SkillComposer does.
        $matched = preg_match('/^\s*---[^\S\r\n]*\R(.*?)\R---[^\S\r\n]*(?:\R|$)(.*)$/s', (string) file_get_contents($file), $m);
        expect($matched)->toBe(1, "{$dir}: no frontmatter");

        $meta = Yaml::parse($m[1]);

        expect($meta)->toBeArray()
            ->and($meta['name'] ?? null)->toBe($dir, "{$dir}: name must match its directory")
            ->and($dir)->toMatch('/^telemetry-insights-[a-z0-9]+(-[a-z0-9]+)*$/')
            ->and($meta['description'] ?? null)->toBeString()->not->toBeEmpty()
            ->and(strlen((string) $meta['description']))->toBeLessThanOrEqual(1024)
            ->and(trim($m[2]))->not->toBeEmpty();
    }
});

it('keeps resources/boost in the dist archive', function () {
    $patterns = collect(file(insightsPackageRoot().'/.gitattributes', FILE_IGNORE_NEW_LINES) ?: [])
        ->filter(fn (string $line) => str_contains($line, 'export-ignore'))
        ->map(fn (string $line) => ltrim((string) strtok(trim($line), " \t"), '/'));

    foreach (insightsBoostSkills() as $file) {
        $path = ltrim(str_replace(insightsPackageRoot(), '', $file), '/');

        foreach ($patterns as $pattern) {
            expect($path === $pattern || str_starts_with($path, $pattern.'/') || fnmatch($pattern, $path))
                ->toBeFalse("{$path} is export-ignored by [{$pattern}]");
        }
    }
});

it('documents every agent tool, and only tools and arguments that exist', function () {
    $tools = insightsMcpTools();
    $content = (string) file_get_contents(insightsBoostSkills()['telemetry-insights-triage']);

    expect($tools)->not->toBeEmpty();

    foreach (array_keys($tools) as $name) {
        expect($content)->toContain("`{$name}`");
    }

    // The tools table: | `tool` | what | `arg`, `arg` (default) |
    preg_match_all('/^\| `([a-z_]+)` \|[^|]*\|([^|]*)\|$/m', $content, $rows, PREG_SET_ORDER);
    expect($rows)->toHaveCount(count($tools));

    foreach ($rows as [, $name, $args]) {
        expect($tools)->toHaveKey($name);

        preg_match_all('/`([a-z_]+)`/', $args, $arguments);

        foreach ($arguments[1] as $argument) {
            expect($tools[$name])->toContain($argument);
        }
    }
});

it('names every status and kind list_issues accepts', function () {
    $content = (string) file_get_contents(insightsBoostSkills()['telemetry-insights-triage']);

    foreach ([...IssueStatus::cases(), ...IssueKind::cases()] as $case) {
        expect($content)->toContain("`{$case->value}`");
    }
});
