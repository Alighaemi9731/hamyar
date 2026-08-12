<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Sales\Enums\InvoiceStatus;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\InvoiceTotals;
use App\Modules\Sales\Services\TotalRounder;
use App\Modules\Settings\Services\TenantShopSettings;
use App\Support\Money;
use App\Support\Settings\RoundingDirection;
use App\Support\Settings\ShopSettings;
use App\Support\Tenancy\TenantContext;

/**
 * ADR 0009, asserted.
 *
 * Phase 7's P&L and Phase 9's tax reports have to reproduce these figures exactly, so
 * the rules are pinned here rather than left to whoever reads the service next.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->withDomain()->create();

    /** @var Warehouse $warehouse */
    $warehouse = inTenantContext($this->tenant, fn () => Warehouse::factory()->create([
        'is_sellable' => true,
        'is_default' => true,
    ]));

    $this->warehouse = $warehouse;
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * A draft with the given lines, totalled.
 *
 * @param  list<array{price: int, quantity?: int, discount?: int, vat?: int}>  $lines
 * @param  array{rounding_step?: int, rounding_direction?: string}|null  $snapshot
 */
function totalled(array $lines, int $invoiceDiscount = 0, ?array $snapshot = null): SalesInvoice
{
    /** @var Tenant $tenant */
    $tenant = test()->tenant;
    /** @var Warehouse $warehouse */
    $warehouse = test()->warehouse;

    /** @var SalesInvoice $invoice */
    $invoice = inTenantContext($tenant, function () use ($lines, $invoiceDiscount, $snapshot, $warehouse): SalesInvoice {
        $product = Product::factory()->create(['type' => 'standard']);

        $invoice = SalesInvoice::query()->create([
            'branch_id' => $warehouse->branch_id,
            'status' => InvoiceStatus::Draft,
            'discount_amount' => $invoiceDiscount,
            'settings_snapshot' => $snapshot,
        ]);

        foreach ($lines as $line) {
            $variant = ProductVariant::factory()->for($product)->create();

            $invoice->items()->create([
                'product_variant_id' => $variant->id,
                'description' => 'کالا',
                'quantity' => $line['quantity'] ?? 1,
                'unit_price' => $line['price'],
                'discount_amount' => $line['discount'] ?? 0,
                'vat_rate' => $line['vat'] ?? 0,
                'line_total' => 0,
            ]);
        }

        return app(InvoiceTotals::class)->recalculate($invoice->refresh());
    });

    return $invoice;
}

/* --------------------------------------------------- rule 1: whole toman -- */

it('never leaves a figure that is not a whole number of toman', function (): void {
    // The bug that forced the ADR: 10% VAT on 888,199,999 rial is 88,819,999 — nine
    // tenths of a toman — and `Money` refuses to render it at all.
    $invoice = totalled([
        ['price' => 892_000_000, 'discount' => 12_000_000, 'vat' => 10],
        ['price' => 4_850_000, 'quantity' => 2, 'vat' => 10],
    ], invoiceDiscount: 1_500_000);

    inTenantContext($this->tenant, function () use ($invoice): void {
        $whole = fn (int $rial): bool => $rial % Money::RIAL_PER_TOMAN === 0;

        expect($whole($invoice->vat_amount))->toBeTrue();
        expect($whole($invoice->total))->toBeTrue();

        foreach ($invoice->items as $item) {
            expect($whole($item->line_total))->toBeTrue();
            expect($whole($item->vat_amount))->toBeTrue();
        }

        // And every one of them renders, which is the point.
        expect(fn () => Money::formatWithUnit($invoice->total, Money::UNIT_TOMAN))->not->toThrow(Throwable::class);
    });
});

it('floors VAT rather than rounding it up', function (): void {
    // Never charge a customer more tax than the exact calculation.
    $invoice = totalled([['price' => 1_000_009, 'vat' => 9]]);

    inTenantContext($this->tenant, function () use ($invoice): void {
        // Exact: 9% of 1,000,009 = 90,000.81 → 90,000 rial, floored to whole toman.
        expect($invoice->items()->firstOrFail()->vat_amount)->toBe(90_000);
    });
});

/* ------------------------------------------ rule 1: parts sum to the whole -- */

it('carries the discount residue to the largest line so the parts sum exactly', function (): void {
    // A discount that does not divide evenly across three lines of different sizes.
    $invoice = totalled([
        ['price' => 7_000_000],
        ['price' => 2_000_000],
        ['price' => 1_000_000],
    ], invoiceDiscount: 1_000_000);

    inTenantContext($this->tenant, function () use ($invoice): void {
        $lineSum = (int) $invoice->items()->sum('line_total');

        // subtotal − discount, with nothing lost or invented in the distribution.
        expect($lineSum)->toBe($invoice->subtotal - $invoice->discount_amount);
    });
});

/* ------------------------------------------- rule 2 & 3: the total rounds -- */

it('rounds the total once and records what it moved', function (): void {
    $invoice = totalled(
        [['price' => 12_847_300]],
        snapshot: ['rounding_step' => 10_000, 'rounding_direction' => 'nearest'],
    );

    inTenantContext($this->tenant, function () use ($invoice): void {
        expect($invoice->total)->toBe(12_850_000);
        expect($invoice->rounding_adjustment)->toBe(2_700);
    });
});

it('applies every direction the way the ADR says', function (int $step, string $direction, int $expected, int $adjustment): void {
    $invoice = totalled(
        [['price' => 12_847_300]],
        snapshot: ['rounding_step' => $step, 'rounding_direction' => $direction],
    );

    inTenantContext($this->tenant, function () use ($invoice, $expected, $adjustment): void {
        expect($invoice->total)->toBe($expected);
        expect($invoice->rounding_adjustment)->toBe($adjustment);
    });
})->with([
    'none' => [10_000, 'none', 12_847_300, 0],
    'nearest' => [10_000, 'nearest', 12_850_000, 2_700],
    'down' => [10_000, 'down', 12_840_000, -7_300],
    'up' => [10_000, 'up', 12_850_000, 2_700],
]);

it('diverges between nearest and up exactly where the ADR says it does', function (): void {
    $nearest = totalled(
        [['price' => 12_842_000]],
        snapshot: ['rounding_step' => 10_000, 'rounding_direction' => 'nearest'],
    );

    $up = totalled(
        [['price' => 12_842_000]],
        snapshot: ['rounding_step' => 10_000, 'rounding_direction' => 'up'],
    );

    inTenantContext($this->tenant, function () use ($nearest, $up): void {
        expect($nearest->total)->toBe(12_840_000);
        expect($nearest->rounding_adjustment)->toBe(-2_000);

        expect($up->total)->toBe(12_850_000);
        expect($up->rounding_adjustment)->toBe(8_000);
    });
});

it('never moves a total that is already on the step, in any direction', function (string $direction): void {
    // Including `up`, which would otherwise add a whole step to a payable number.
    $invoice = totalled(
        [['price' => 12_850_000]],
        snapshot: ['rounding_step' => 10_000, 'rounding_direction' => $direction],
    );

    inTenantContext($this->tenant, function () use ($invoice): void {
        expect($invoice->total)->toBe(12_850_000);
        expect($invoice->rounding_adjustment)->toBe(0);
    });
})->with(['none', 'nearest', 'down', 'up']);

/* ----------------------------------------- the paper always adds up -- */

it('always has line totals plus the adjustment equal to the invoice total', function (int $price, int $discount, int $vat, int $step): void {
    // The assertion the print layouts rest on: whatever the arithmetic does, the
    // figures a customer can see on the paper must reconcile in front of them.
    $invoice = totalled(
        [
            ['price' => $price, 'vat' => $vat],
            ['price' => 4_850_000, 'quantity' => 3, 'vat' => $vat],
        ],
        invoiceDiscount: $discount,
        snapshot: ['rounding_step' => $step, 'rounding_direction' => 'nearest'],
    );

    inTenantContext($this->tenant, function () use ($invoice): void {
        $lineSum = (int) $invoice->items()->sum('line_total');

        expect($lineSum + $invoice->shipping_amount + $invoice->rounding_adjustment)
            ->toBe($invoice->total);
    });
})->with([
    'plain' => [10_000_000, 0, 0, 1_000],
    'with vat' => [892_000_000, 0, 10, 1_000],
    'with discount' => [892_000_000, 1_500_000, 0, 1_000],
    'awkward discount' => [892_000_007, 1_500_003, 9, 10_000],
    'coarse step' => [12_847_300, 333_333, 10, 10_000],
]);

/* -------------------------------------------------------------- settings -- */

it('defaults a shop to a 1,000 rial step and nearest, per the ADR', function (): void {
    inTenantContext($this->tenant, function (): void {
        $rounding = app(ShopSettings::class)->rounding();

        expect($rounding->step)->toBe(TenantShopSettings::DEFAULT_ROUNDING_STEP);
        expect($rounding->step)->toBe(TotalRounder::DEFAULT_STEP);
        expect($rounding->direction)->toBe(RoundingDirection::Nearest);
    });
});

it('lets a shop choose its own step and direction', function (): void {
    $this->tenant->update(['settings' => ['rounding' => ['step' => 10_000, 'direction' => 'down']]]);

    inTenantContext($this->tenant, function (): void {
        $rounding = app(ShopSettings::class)->rounding();

        expect($rounding->step)->toBe(10_000);
        expect($rounding->direction)->toBe(RoundingDirection::Down);
        expect($rounding->toSnapshot())->toBe([
            'rounding_step' => 10_000,
            'rounding_direction' => 'down',
        ]);
    });
});

it('falls back to the defaults rather than to no rounding when a setting is nonsense', function (): void {
    // A missing or malformed setting resolving to "no rounding" would quietly change
    // the arithmetic on every invoice a shop issues.
    $this->tenant->update(['settings' => ['rounding' => ['step' => 'خیلی', 'direction' => 'sideways']]]);

    inTenantContext($this->tenant, function (): void {
        $rounding = app(ShopSettings::class)->rounding();

        expect($rounding->step)->toBe(TenantShopSettings::DEFAULT_ROUNDING_STEP);
        expect($rounding->direction)->toBe(RoundingDirection::Nearest);
    });
});
