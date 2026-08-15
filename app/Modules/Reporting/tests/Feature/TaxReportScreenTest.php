<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\Party;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Sales\Models\SalesInvoice;
use App\Support\Tenancy\TenantContext;

/**
 * The VAT summary — and the nine rial that prove it is reading rather than recomputing.
 *
 * ## The prices are un-round on purpose
 *
 * `docs/testing.md`: money fixtures use non-round amounts by default, because rounding
 * only shows up when there is a remainder. Every line here is priced at **8,881,990 rial**
 * — a whole toman, so it is a price a shop can actually charge, and one whose 10% is
 * *not*: 888,199 rial is 88,819.9 toman.
 *
 * ADR 0009 rule 1 says per-line VAT floors to a whole toman, so the stored figure is
 * **888,190**. Two such lines:
 *
 * | figure                          | value        |
 * |---------------------------------|--------------|
 * | taxable base (2 × 8,881,990)    | 17,763,980   |
 * | VAT, floored per line           |  1,776,380   |
 * | VAT if recomputed over the total|  1,776,398   |
 *
 * **Eighteen rial apart**, and that gap is the entire test. A report that re-derives VAT
 * from a period's revenue at today's rate produces the second number: it rounds once over
 * a month instead of once per line, disagrees with every invoice it summarises, and does
 * so in the shop's favour — which is the direction a tax authority notices (ADR 0009,
 * Amendment). Against round prices both implementations return the same figure and the
 * defect ships.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = tenantUrl($this->tenant);

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    /** @var array{User, User, Warehouse, ProductVariant, ProductVariant, ProductVariant, Party} $fixtures */
    $fixtures = app(TenantContext::class)->runFor($this->tenant, function (): array {
        $owner = User::factory()->create(['name' => 'مالک']);
        $owner->assignRole('Owner');

        $cashier = User::factory()->create();
        $cashier->assignRole('Cashier');

        $warehouse = Warehouse::factory()->create(['is_sellable' => true, 'is_default' => true]);

        Account::factory()->create(['type' => Account::TYPE_CASH, 'is_default' => true]);
        Account::factory()->create(['type' => Account::TYPE_SALES]);

        $ledger = app(StockLedger::class);
        $variants = [];

        foreach (['باتری', 'گلس', 'کابل'] as $name) {
            $variant = ProductVariant::factory()
                ->for(Product::factory()->create(['name' => $name, 'type' => 'standard']))
                ->create();

            $ledger->record($variant->id, $warehouse->id, 50, MovementType::Purchase, unitCost: 1_230_000);

            $variants[] = $variant;
        }

        return [$owner, $cashier, $warehouse, $variants[0], $variants[1], $variants[2], Party::factory()->create()];
    });

    [$this->owner, $this->cashier, $this->warehouse, $this->first, $this->second, $this->third, $this->party] = $fixtures;

    // Two taxable lines on one invoice: the per-line floor has to happen twice for the
    // gap against a period-level recompute to exist at all.
    sellTaxed([
        ['variant_id' => $this->first->id, 'unit_price' => 8_881_990],
        ['variant_id' => $this->second->id, 'unit_price' => 8_881_990],
    ], vat: true);

    // A zero-rated line — «معاف یا نرخ صفر», the other half of «فروش بر اساس وضعیت مالیاتی».
    sellTaxed([['variant_id' => $this->third->id, 'unit_price' => 5_000_000]], vat: false);
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * One finalised invoice through the real POS endpoint.
 *
 * On credit rather than paid: a payment has to match the rounded total exactly, and
 * predicting the rounding is not what this file tests.
 *
 * @param  list<array{variant_id: int, unit_price: int}>  $lines
 */
function sellTaxed(array $lines, bool $vat): int
{
    /** @var Warehouse $warehouse */
    $warehouse = test()->warehouse;
    /** @var User $owner */
    $owner = test()->owner;
    /** @var Party $party */
    $party = test()->party;
    /** @var string $url */
    $url = test()->url;

    test()->actingAs($owner)->post($url.'/sales/pos', [
        'branch_id' => $warehouse->branch_id,
        'party_id' => $party->id,
        'salesperson_id' => $owner->id,
        'unit' => 'rial',
        'action' => 'finalise',
        'vat_applied' => $vat,
        'discount_amount' => 0,
        'shipping_amount' => 0,
        'notes' => null,
        'lines' => array_map(static fn (array $line): array => [
            'unit_id' => null,
            'variant_id' => $line['variant_id'],
            'quantity' => 1,
            'unit_price' => $line['unit_price'],
            'discount_amount' => 0,
        ], $lines),
        'payments' => [],
    ])->assertSessionHasNoErrors()->assertRedirect();

    /** @var Tenant $tenant */
    $tenant = test()->tenant;

    /** @var SalesInvoice $invoice */
    $invoice = app(TenantContext::class)->runFor(
        $tenant,
        fn (): SalesInvoice => SalesInvoice::query()->latest('id')->firstOrFail(),
    );

    /** @var int|numeric-string $id */
    $id = $invoice->getKey();

    return (int) $id;
}

/* ----------------------------------------------------------------- VAT -- */

it('sums the VAT the invoices stored, not the VAT a rate would produce', function (): void {
    $this->actingAs($this->owner)
        ->get($this->url.'/reporting/tax?cut=monthly')
        ->assertOk()
        ->assertInertia(function ($page): void {
            $totals = totalsOf($page);

            // The fixture contains the subject — without this the figures below could be
            // zero equals zero and nobody would know.
            expect(rialOf($totals['taxable_base']))->toBe(17_763_980);

            // Floored per line, per ADR 0009 rule 1.
            expect(rialOf($totals['vat']))->toBe(1_776_380);

            // And explicitly NOT the figure a period-level recompute gives. Eighteen rial
            // is small; being eighteen rial away from the invoices is not.
            expect(rialOf($totals['vat']))->not->toBe(intdiv(17_763_980 * 10, 100));

            // The zero-rated sale is reported beside the taxable base, never inside it.
            expect(rialOf($totals['exempt_base']))->toBe(5_000_000);
        });
});

it('splits the period by rate, with the zero-rated lines named as such', function (): void {
    $this->actingAs($this->owner)
        ->get($this->url.'/reporting/tax?cut=rate')
        ->assertOk()
        ->assertInertia(function ($page): void {
            $byRate = [];

            foreach (rowsOf($page) as $row) {
                $byRate[is_numeric($row['rate'] ?? null) ? (int) $row['rate'] : -1] = $row;
            }

            expect(array_keys($byRate))->toBe([0, 10]);

            expect($byRate[10]['lines'])->toBe(2)
                ->and(rialOf($byRate[10]['taxable_base']))->toBe(17_763_980)
                ->and(rialOf($byRate[10]['vat']))->toBe(1_776_380);

            expect($byRate[0]['lines'])->toBe(1)
                ->and(rialOf($byRate[0]['vat']))->toBe(0)
                ->and($byRate[0]['label'])->toBe('معاف / نرخ صفر');
        });
});

it('drops a voided invoice from the return while its number survives', function (): void {
    $invoiceId = sellTaxed([['variant_id' => $this->first->id, 'unit_price' => 8_881_990]], vat: true);

    // Before: three taxable lines.
    $this->actingAs($this->owner)
        ->get($this->url.'/reporting/tax?cut=monthly')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('totals.vat.value', 1_776_380 + 888_190)
            ->etc()
        );

    $this->actingAs($this->owner)
        ->post($this->url.'/sales/invoices/'.$invoiceId.'/void', ['reason' => 'اشتباه صندوق‌دار'])
        ->assertSessionHasNoErrors();

    /*
    | After: back to two. A void invoice keeps its number — a gap in the sequence is what
    | the taxman asks about — so the row is still there with a `vat_amount` on it, and a
    | report that filtered only on `deleted_at` would charge the shop tax on a sale it
    | un-made.
    */
    $this->actingAs($this->owner)
        ->get($this->url.'/reporting/tax?cut=monthly')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('totals.vat.value', 1_776_380)
            ->where('totals.taxable_base.value', 17_763_980)
            ->etc()
        );

    app(TenantContext::class)->runFor($this->tenant, function () use ($invoiceId): void {
        $voided = SalesInvoice::query()->findOrFail($invoiceId);

        expect($voided->status->value)->toBe('void')
            ->and($voided->number)->not->toBeNull();
    });
});

/* ------------------------------------------------------------ boundary -- */

it('refuses the tax summary to somebody without the back-office permission', function (): void {
    /*
    | A Cashier holds `reporting.view` and sees the sales report. A VAT return is filed,
    | not worked from, and has no counter-side counterpart — so unlike the financial cuts
    | this one is a single back-office permission and the Cashier is out.
    */
    $this->actingAs($this->cashier)
        ->get($this->url.'/reporting/tax')
        ->assertForbidden();

    $this->actingAs($this->cashier)
        ->get($this->url.'/reporting')
        ->assertOk()
        ->assertInertia(function ($page): void {
            // And the index does not offer them a row that would 403.
            expect(reportKeys($page, 'tax'))->toBe([]);
        });
});

it('lists both tax rows on the index for the owner', function (): void {
    $this->actingAs($this->owner)
        ->get($this->url.'/reporting')
        ->assertOk()
        ->assertInertia(function ($page): void {
            expect(reportKeys($page, 'tax'))->toBe(['tax.vat_monthly', 'tax.by_rate']);
        });
});

it('downloads a workbook whose money carries both a rial column and a formatted one', function (): void {
    $response = $this->actingAs($this->owner)->get($this->url.'/reporting/tax/export?cut=monthly');

    $response->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('.xlsx');
});

/* ----------------------------------------------------------- isolation -- */

it('reports a shop its own VAT and not the shop next door', function (): void {
    $other = Tenant::factory()->withDomain()->create();
    subscribe($other, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($other);

    $neighbour = app(TenantContext::class)->runFor($other, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Owner');

        return $user;
    });

    // The positive half, so the emptiness below is isolation rather than an empty world.
    $this->actingAs($this->owner)
        ->get($this->url.'/reporting/tax')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('totals.vat.value', 1_776_380)->etc());

    $this->actingAs($neighbour)
        ->get(tenantUrl($other).'/reporting/tax')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('rows', [])
            ->where('totals.vat.value', 0)
            ->etc()
        );
})->group('isolation');
