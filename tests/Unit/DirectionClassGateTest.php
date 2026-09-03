<?php

declare(strict_types=1);

/**
 * Tests for `bin/check-direction-classes` (golden rule 9).
 *
 * The gate is only useful if it is both strict and quiet: a false positive on English
 * prose trains everyone to add `rtl-allow` reflexively, which is worse than no gate.
 * These cases are the ones that actually bit during Phase 0.
 */

/**
 * Runs the gate against a single throwaway file and reports whether it flagged it.
 */
function gateFlags(string $contents, string $extension = 'tsx'): bool
{
    $directory = base_path('resources/js/__gate_fixture');

    if (! is_dir($directory)) {
        mkdir($directory, 0o777, true);
    }

    $path = $directory.'/fixture.'.$extension;
    file_put_contents($path, $contents);

    $process = proc_open(
        [PHP_BINARY, base_path('bin/check-direction-classes')],
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

    return str_contains($output, 'RTL gate FAILED');
}

it('flags physical direction utilities', function (string $className): void {
    expect(gateFlags('export const x = <div className="'.$className.'" />;'))->toBeTrue();
})->with([
    'ml-2',
    'mr-4',
    'pl-1',
    'pr-1',
    'left-0',
    'right-0',
    'text-left',
    'text-right',
    'float-left',
    'border-l',
    'border-l-2',
    'rounded-r',
    'ml-[3px]',
    'ml-1/2',
    'sm:ml-4',
    'hover:text-right',
    '-ml-8',
]);

it('accepts logical utilities', function (string $className): void {
    expect(gateFlags('export const x = <div className="'.$className.'" />;'))->toBeFalse();
})->with([
    'ms-2',
    'me-4',
    'ps-1',
    'pe-1',
    'start-0',
    'end-0',
    'text-start',
    'text-end',
    'border-s',
    'border-e-2',
    'rounded-s',
    'float-start',
    '-ms-8',
]);

it('does not flag English prose that merely contains a direction word', function (string $comment): void {
    expect(gateFlags('// '.$comment))->toBeFalse();
})->with([
    'the value reads left-to-right while the layout stays RTL',
    'previous is the right-pointing chevron in RTL',
    'anchored to the top-left corner of the sheet',
    'right-hand side of the invoice',
]);

it('does not flag compound utilities that merely end in a direction segment', function (string $className): void {
    expect(gateFlags('export const x = <div className="'.$className.'" />;'))->toBeFalse();
})->with([
    'slide-in-from-left-2',
    'slide-out-to-right-10',
    'data-[side=left]:slide-in-from-left-2',
]);

it('honours an rtl-allow comment on the same line', function (): void {
    expect(gateFlags('export const x = <div className="ml-2" />; // rtl-allow: physical on purpose'))
        ->toBeFalse();
});

it('honours an rtl-allow comment on the preceding line', function (): void {
    $source = <<<'TSX'
        // pinned to the physical paper edge for the thermal printer. rtl-allow
        export const x = <div className="ml-2" />;
        TSX;

    expect(gateFlags($source))->toBeFalse();
});

/**
 * Hand-written CSS gets the same rule in its own vocabulary. The class rules above only
 * ever saw Tailwind tokens, so ~4,700 lines of stylesheet under `resources/` were never
 * gated — and `text-align: left` mirrors a Persian page exactly as thoroughly as
 * `text-left` does. Two were sitting in `landing.css` when this was added.
 */
it('flags physical CSS properties', function (string $declaration): void {
    expect(gateFlags('.x { '.$declaration.' }', 'css'))->toBeTrue();
})->with([
    'margin-left: 4px;',
    'margin-right: 4px;',
    'padding-left: 1rem;',
    'padding-right: 1rem;',
    'left: 0;',
    'right: 0;',
    'text-align: left;',
    'text-align: right;',
    'float: left;',
    'clear: right;',
    'border-left: 1px solid red;',
    'border-right-color: red;',
    'border-top-left-radius: 4px;',
]);

it('accepts logical CSS properties', function (string $declaration): void {
    expect(gateFlags('.x { '.$declaration.' }', 'css'))->toBeFalse();
})->with([
    'margin-inline-start: 4px;',
    'padding-inline-end: 1rem;',
    'inset-inline-start: 0;',
    'text-align: start;',
    'text-align: end;',
    'float: inline-start;',
    'border-inline-start: 1px solid red;',
    'border-start-start-radius: 4px;',
]);

/**
 * A physical *paint* direction is not a physical *layout* direction. Flagging these is
 * how a gate earns a reputation for noise and gets switched off.
 */
it('leaves paint directions and prose alone in CSS', function (string $line): void {
    expect(gateFlags($line, 'css'))->toBeFalse();
})->with([
    'background-position' => '.x { background-position: left center; }',
    'gradient' => '.x { background: linear-gradient(to right, #000, #fff); }',
    'transform-origin' => '.x { transform-origin: right top; }',
    'prose' => '/* the old rule used a physical margin here */',
]);

it('honours rtl-allow in CSS', function (): void {
    expect(gateFlags(".x { text-align: left; } /* rtl-allow: a printed sheet's paper edge */", 'css'))
        ->toBeFalse();
});
