<?php

declare(strict_types=1);

/**
 * Tests for `bin/check-bundle-boundary` (ADR 0016, ADR 0020).
 *
 * The landing and the app are two Vite entries that must never import each other; since
 * the brand layer they share exactly one leaf, `resources/css/brand.css`. A gate for that
 * is only useful if it catches every crossing AND stays quiet about the prose in these
 * heavily-commented stylesheets — the first run of this gate flagged its own docblock,
 * which quotes the `@import 'tailwindcss'` line it is describing.
 */

/**
 * Writes a throwaway file, runs the gate, and reports whether it refused.
 */
function boundaryFlags(string $relativePath, string $contents): bool
{
    $path = base_path($relativePath);
    $directory = dirname($path);
    $createdDirectory = ! is_dir($directory);

    if ($createdDirectory) {
        mkdir($directory, 0o777, true);
    }

    file_put_contents($path, $contents);

    $process = proc_open(
        [PHP_BINARY, base_path('bin/check-bundle-boundary')],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        base_path()
    );

    $output = '';

    if (is_resource($process)) {
        $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
    }

    unlink($path);

    if ($createdDirectory) {
        rmdir($directory);
    }

    return str_contains($output, 'Bundle-boundary gate FAILED');
}

it('passes on the repository as it stands', function (): void {
    // No fixture: the tree itself must satisfy the gate, or every case below is
    // measuring a pre-existing failure rather than its own fixture.
    $process = proc_open(
        [PHP_BINARY, base_path('bin/check-bundle-boundary')],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        base_path()
    );

    $output = '';

    if (is_resource($process)) {
        $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
    }

    expect($output)->toContain('share only the brand leaf');
});

it('refuses the landing importing the app', function (string $contents): void {
    expect(boundaryFlags('resources/landing/__gate_fixture.css', $contents))->toBeTrue();
})->with([
    'app stylesheet' => "@import '../css/app.css';",
    'app component' => "import { Money } from '../js/components/domain/money';",
    'alias' => "import { formatMoney } from '@/lib/money';",
]);

it('refuses the app importing the landing', function (): void {
    expect(boundaryFlags('resources/js/__gate_fixture.ts', "import '../landing/landing.js';"))->toBeTrue();
});

it('refuses a selector rule in the shared leaf', function (): void {
    $leaf = base_path('resources/css/brand.css');
    $original = (string) file_get_contents($leaf);

    try {
        file_put_contents($leaf, $original."\n.card {\n  color: red;\n}\n");

        $process = proc_open(
            [PHP_BINARY, base_path('bin/check-bundle-boundary')],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path()
        );

        $output = '';

        if (is_resource($process)) {
            $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }

        expect($output)->toContain('Bundle-boundary gate FAILED')
            ->and($output)->toContain('@import, @theme and @font-face');
    } finally {
        file_put_contents($leaf, $original);
    }
});

it('accepts what the two bundles legitimately do', function (string $path, string $contents): void {
    expect(boundaryFlags($path, $contents))->toBeFalse();
})->with([
    'the landing imports the brand leaf' => ['resources/landing/__gate_fixture.css', "@import '../css/brand.css';"],
    'the landing imports its own section' => ['resources/landing/__gate_fixture.css', "@import './sections/hero.css';"],
    'the landing imports a package' => ['resources/landing/__gate_fixture.css', "@import 'tailwindcss';"],
    'a prose mention of the other side' => ['resources/landing/__gate_fixture.css', "/* The app imports '../js/app.tsx'; this file must not. */\n.x { color: red; }"],
    'the app imports its own component' => ['resources/js/__gate_fixture.ts', "import { Money } from './components/domain/money';"],
]);
