<?php

declare(strict_types=1);

/**
 * The invoice table layout, guarded.
 *
 * ## Why this is a source assertion and not a rendered one
 *
 * The defect it exists to prevent was found by rendering a real invoice: with an `auto`
 * table layout, a realistic Persian product name — «گوشی موبایل اپل آیفون ۱۵ پرو مکس
 * ظرفیت ۲۵۶ گیگابایت تیتانیوم طبیعی», which is a normal name for a phone, not an edge
 * case — squeezed the money columns until three figures ran together as
 * `96,636,7981,200,00089,200,0001`.
 *
 * Measuring that properly needs a browser: it is a question about rendered box widths.
 * Pest 4 can do it, but browser testing is not wired into this repo or its CI yet, and
 * a test that silently does not run is worse than one that is honest about its reach.
 *
 * So this asserts the *mechanism* rather than the *outcome*, in the same spirit as
 * `bin/check-direction-classes`: it fails the moment somebody removes the fixed layout
 * or the explicit column widths, which is exactly how this regresses. It cannot catch a
 * width that is merely too small.
 *
 * **When browser testing lands, replace this with a rendered assertion** that loads the
 * seeded invoice and checks no two cells in a row overlap.
 */

/**
 * The component's source.
 *
 * Resolved inside each test rather than at file scope: Pest evaluates a test file's
 * body at collection time, before the application is booted, and `base_path()` needs a
 * container.
 */
function printComponentSource(): string
{
    $path = base_path('app/Modules/Sales/resources/js/pages/Invoices/Print.tsx');

    expect($path)->toBeFile();

    return (string) file_get_contents($path);
}

it('keeps the invoice item table on a fixed layout with explicit column widths', function (): void {
    $source = printComponentSource();

    // `table-fixed` is what stops the description column from starving the money
    // columns; `auto` is the default and is what produced the collision.
    expect($source)->toContain('table-fixed');

    // A colgroup sized for the widest realistic figure in each numeric column.
    expect($source)->toContain('<colgroup>');

    foreach (['w-[7mm]', 'w-[11mm]', 'w-[23mm]', 'w-[20mm]', 'w-[25mm]'] as $width) {
        expect($source)->toContain($width);
    }
});

it('keeps every money cell on one line', function (): void {
    $source = printComponentSource();

    // A wrapped figure is as unreadable as a collided one: «۸۹,۲۰۰,» on one line and
    // «۰۰۰» on the next is not a number anybody can check.
    $moneyCells = substr_count($source, 'text-end tabular whitespace-nowrap');

    // Quantity, unit price, discount and line total.
    expect($moneyCells)->toBeGreaterThanOrEqual(4);
});

it('lets a long Persian product name break rather than overflow its cell', function (): void {
    $source = printComponentSource();

    // The description column is the only elastic one, so it is also the only one
    // allowed to wrap — and it must, or a long name pushes the table wider than the
    // paper.
    expect($source)->toContain('break-words');
});
