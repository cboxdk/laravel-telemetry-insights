<?php

declare(strict_types=1);

use Cbox\TelemetryInsights\Testing\ScreenshotManifest;

/**
 * The same guard the UI package carries: the manifest claims to be the one
 * place a screen gets updated, which is only true if something checks that
 * the committed PNGs, the manifest and the pages embedding them agree.
 *
 * Without it a screenshot can be captured, committed and referenced by
 * nothing — which is what had happened to six of the UI package's eleven.
 */
function insightsDocs(): array
{
    $root = dirname(__DIR__, 2).'/docs';
    $files = [];

    /** @var iterable<SplFileInfo> $iterator */
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'md') {
            $files[$file->getPathname()] = (string) file_get_contents($file->getPathname());
        }
    }

    return $files;
}

it('commits a PNG for every screen the manifest names', function (): void {
    foreach (ScreenshotManifest::all() as $key => $shot) {
        expect(dirname(__DIR__, 2)."/docs/screenshots/{$key}.png")
            ->toBeFile("docs/screenshots/{$key}.png is missing — recapture it");
    }
});

it('keeps no screenshot the manifest does not name', function (): void {
    $found = array_map(
        static fn (string $path): string => basename($path, '.png'),
        glob(dirname(__DIR__, 2).'/docs/screenshots/*.png') ?: [],
    );

    expect(array_values(array_diff($found, array_keys(ScreenshotManifest::all()))))->toBe([]);
});

it('references every screenshot, with the manifest caption, from a page that resolves', function (): void {
    $docs = insightsDocs();
    $seen = [];

    foreach ($docs as $path => $body) {
        preg_match_all('/!\[([^\]]*)\]\(([^)]*screenshots\/([a-z0-9-]+)\.png)\)/', $body, $matches, PREG_SET_ORDER);

        foreach ($matches as [, $caption, $href, $key]) {
            $shot = ScreenshotManifest::all()[$key] ?? null;

            expect($shot)->not->toBeNull(basename($path)." embeds an unknown screenshot [{$key}]");
            expect($caption)->toBe($shot['caption'], basename($path)." caption for [{$key}] has drifted from the manifest");
            expect(realpath(dirname($path).'/'.$href))->not->toBeFalse(basename($path)." links to [{$href}], which does not resolve");

            $seen[$key] = true;
        }
    }

    foreach (array_keys(ScreenshotManifest::all()) as $key) {
        expect($seen)->toHaveKey($key, "docs/screenshots/{$key}.png is committed but no page embeds it");
    }
});
