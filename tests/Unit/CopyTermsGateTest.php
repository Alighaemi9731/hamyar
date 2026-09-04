<?php

declare(strict_types=1);

/**
 * Tests for `bin/check-copy-terms` (docs/brand/voice.md, made mechanical).
 *
 * The gate is only useful if it is both strict and quiet: a false positive on a docblock
 * that quotes the phrase it forbids trains everyone to add `copy-allow` reflexively,
 * which is worse than no gate.
 */

/**
 * Runs the gate against a single throwaway file and reports whether it flagged it.
 */
function copyGateFlags(string $contents, string $extension = 'tsx'): bool
{
    $directory = base_path('resources/js/__copy_fixture');

    if (! is_dir($directory)) {
        mkdir($directory, 0o777, true);
    }

    $path = $directory.'/fixture.'.$extension;
    file_put_contents($path, $contents);

    $process = proc_open(
        [PHP_BINARY, base_path('bin/check-copy-terms')],
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
    rmdir($directory);

    return str_contains($output, '__copy_fixture');
}

it('flags the words the voice forbids', function (string $copy): void {
    expect(copyGateFlags("export const x = '{$copy}';"))->toBeTrue();
})->with([
    'an unverifiable adjective' => 'راهکار جامع و هوشمند',
    'Arabic ي' => 'يک دستگاه',
    'Arabic-Indic digits' => '٣ دستگاه',
    'an exclamation mark' => 'ثبت شد!',
    'a compound with a space' => 'ثبت نام کنید',
    'a colloquial noun' => 'مغازه شما',
    'a colloquial preposition' => 'توی کشو',
]);

it('passes copy in the register', function (string $copy): void {
    expect(copyGateFlags("export const x = '{$copy}';"))->toBeFalse();
})->with([
    'the glossary word' => 'فروشگاه شما',
    'the ZWNJ compound' => 'ثبت‌نام کنید',
    'a fact with Persian digits' => '۳ دستگاه در انتظار قطعه',
    'a word that merely contains a forbidden one' => 'مجامع',
]);

it('ignores a docblock quoting the phrase it forbids', function (): void {
    expect(copyGateFlags("/** never write «مغازه» here */\nexport const x = 'فروشگاه';"))->toBeFalse();
    expect(copyGateFlags("// توی کشو\nexport const x = 'فروشگاه';"))->toBeFalse();
});

it('lets quoted speech through when the line says so', function (): void {
    expect(copyGateFlags("export const x = 'چک‌های توی کشو'; // copy-allow: the shopkeeper's words"))->toBeFalse();
});

it('reads Blade too, and blanks its comments', function (): void {
    expect(copyGateFlags('<p>ثبت نام</p>', 'blade.php'))->toBeTrue();
    expect(copyGateFlags("{{-- مغازه --}}\n<p>فروشگاه</p>", 'blade.php'))->toBeFalse();
});
