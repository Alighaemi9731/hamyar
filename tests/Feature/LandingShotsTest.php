<?php

declare(strict_types=1);

/**
 * The landing's product screenshots exist, and the page knows which build they show.
 *
 * The claim on the page is «تصویرها از خود نرم‌افزار گرفته شده‌اند — نه ماکت، نه طرح», and
 * on 2026-09-03 it was false: the six shipped images had been captured nine days before a
 * sixteen-phase redesign changed every screen in them. Nothing failed, because nothing
 * checked — a `.webp` on disk looks equally valid whatever it is a picture of.
 *
 * These tests are the check, and deliberately not a date check. "Older than N days" turns
 * `main` red on a schedule for a repository nobody touched that week, which is how a gate
 * gets deleted. What is asserted instead is *coherence*: the tour renders shots, the shots
 * exist, the manifest describes them, and the commit each was captured from is one this
 * repository actually contains. A drifted image is then a visible fact in the manifest
 * rather than an invisible one on disk.
 *
 * Re-capture with `bin/shots` (add `--seed` for the showcase data).
 */

use Illuminate\Support\Facades\File;

/** @return list<string> the shot ids the tour actually renders */
function tourShotIds(): array
{
    $blade = File::get(resource_path('views/landing/sections/tour.blade.php'));

    preg_match_all("~^\s*'([a-z0-9-]+)',\s*$~m", $blade, $matches);

    return array_values(array_unique($matches[1]));
}

it('renders a shot for every tile in the tour', function (): void {
    $ids = tourShotIds();

    expect($ids)->not->toBeEmpty('the tour lists no screenshots at all — has its $screens array moved?');

    foreach ($ids as $id) {
        expect(resource_path("landing/shots/{$id}.webp"))
            ->toBeFile("the tour renders {$id}.webp and the file is not there; run `bin/shots {$id}`");
    }
});

it('describes every shot in the manifest', function (): void {
    $path = resource_path('landing/shots/manifest.json');

    expect($path)->toBeFile('resources/landing/shots/manifest.json is missing; run `bin/shots`');

    /** @var array{screens?: array<string, array{files?: list<string>, git_sha?: string|null}>} $manifest */
    $manifest = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);

    foreach (tourShotIds() as $id) {
        expect($manifest['screens'][$id] ?? null)
            ->not->toBeNull("{$id}.webp is on the landing but not in the manifest — it was added by hand, or captured by something other than bin/shots");

        foreach ($manifest['screens'][$id]['files'] ?? [] as $file) {
            expect(resource_path("landing/shots/{$file}"))->toBeFile("the manifest lists {$file}, which is not on disk");
        }
    }
});

it('records a commit this repository contains for every shot', function (): void {
    $path = resource_path('landing/shots/manifest.json');

    if (! File::exists($path)) {
        $this->markTestSkipped('no manifest yet');
    }

    // A shallow checkout — CI fetches the PR at depth 1 — holds the tip and nothing behind
    // it, so a capture taken on an earlier commit is "missing" there for no reason of its
    // own. The check is only meaningful where git can answer it; elsewhere it steps aside
    // rather than turning the suite red on the clone strategy. The first CI run of this
    // file failed exactly that way.
    exec('git rev-parse --is-shallow-repository 2>/dev/null', $shallow, $gitStatus);

    if ($gitStatus !== 0 || trim((string) ($shallow[0] ?? '')) !== 'false') {
        $this->markTestSkipped('not a full git checkout — the capture commits cannot be verified here');
    }

    /** @var array{screens?: array<string, array{git_sha?: string|null}>} $manifest */
    $manifest = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);

    foreach ($manifest['screens'] ?? [] as $id => $screen) {
        $sha = $screen['git_sha'] ?? null;

        if (! is_string($sha) || $sha === '') {
            // A capture taken outside a git checkout is honest about it; the point of the
            // field is to be able to say which build a picture shows, not to force one.
            continue;
        }

        exec('git cat-file -e '.escapeshellarg($sha.'^{commit}').' 2>/dev/null', $output, $status);

        expect($status)->toBe(0, "{$id} was captured from commit {$sha}, which is not in this repository — the shot came from a branch that no longer exists, so nobody can tell what it shows");
    }
});
