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
 *
 * So this asserts the *mechanism* rather than the *outcome*, in the same spirit as
 * `bin/check-direction-classes`: it fails the moment somebody removes the fixed layout
 * or the explicit column widths, which is exactly how this regresses. It cannot catch a
 * width that is merely too small.
 *
 * ## Browser testing landed, and this file stayed — deliberately
 *
 * 11.1b wired Pest 4 browser tests into CI and added the rendered assertion this
 * docblock used to promise: `tests/Browser/InvoicePrintLayoutTest.php` loads a real
 * invoice with the long name above and measures every cell's box on A4 and A5.
 *
 * It did **not** replace this file, because it could not be shown to catch what this
 * file catches. Removing `table-fixed` — the exact regression this guards — leaves the
 * rendered test green: at A4 the browser balances an `auto` layout into 703px of a
 * 794px sheet, every figure fits its column, and nothing overlaps. The historical
 * collision needed a squeeze that a screen-width preview of A4 does not reproduce, and
 * thermal80 does not use a table at all.
 *
 * So the two guard different halves and both are load-bearing:
 *
 * - **this file** fails the moment the mechanism is removed, which is how the defect
 *   actually regresses — somebody deletes a class that looks decorative;
 * - **the rendered test** fails when a width is merely too small, which is the case
 *   this one admits it cannot see.
 *
 * Deleting either would leave a real gap. What has changed is that the gap this file
 * names is now covered rather than merely acknowledged.
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
