<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Models\Branch;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\StockCountItem;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Quota\QuotaGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Testing\TestResponse;

/**
 * Inventory's meters, at the places a warehousekeeper actually meets them.
 *
 * ## Why this file exists separately from the guard's own suite
 *
 * `Platform/tests/Feature/Quota/*` proves the guard is correct — it counts, it refuses at
 * the ceiling, it is atomic under concurrency — and every one of those tests meters a
 * synthetic `quota.widgets` so the guard's suite breaks when the guard breaks rather than
 * when Inventory renames something. The cost of that isolation is that none of them touch
 * a route, and a guard that is perfect and never called is indistinguishable, from the
 * shop floor, from no guard at all.
 *
 * ## The enforcement site is two clicks past the route that creates the row
 *
 * This is the part Inventory does differently from Sales, and the reason a test that drove
 * only `POST /inventory/transfers` would assert nothing at all. Both metered acts here are
 * charged at the moment the BOOKS move, not at the moment the document appears:
 *
 * - `inventory.transfers` is spent in `TransferService::dispatch()` — the van leaving. A
 *   draft حواله is a warehousekeeper thinking, and charging for the thought is how people
 *   learn to avoid the screen.
 * - `inventory.stock_counts` is spent in `StockCountService::apply()` — the adjustments
 *   being written. An open انبارگردانی is a clipboard; walking the shelves is free.
 *
 * So every test below drives the whole shopkeeper journey (create → add a line → dispatch
 * or apply), and two of them assert the emptiness in between: no counter row exists while
 * the document is still a draft.
 *
 * ## `inventory.branches` is a different kind of claim, not a third copy of the same one
 *
 * It is a {@see App\Support\Quota\Window::Total} metric — a standing capacity rather than a
 * flow — so there is no `usage_counters` row to read and no credit to spend. Usage is
 * whatever the metric's own `measure:` closure counts right now, which for branches is
 * *live, active* rows. Two consequences drive the assertions in the branches section:
 * `quotaRowExists()` must stay FALSE even on the happy path, and closing a branch gives
 * the slot straight back — the only way a capacity metric can ever be refunded, and the
 * only reason a shop is not permanently punished for a branch it opened and shut.
 *
 * ## What is deliberately not here: `inventory.units`
 *
 * Inventory declares the metric and spends it, in `UnitStateMachine::recordAcquisition()`.
 * But no Inventory *route* reaches that method — this module's unit routes are a list, a
 * search and a passport, all of them reads. Both doors a handset can enter a shop through
 * belong to other modules: `Purchasing\ReceivePurchaseInvoice` (a delivery) and
 * `Sales\TradeInIntake` (a معاوضه over the counter), and metering them at the state machine
 * rather than at either route is precisely what stops the trade-in being a loophole. There
 * is therefore no honest enforcement-site test to write from here; it belongs beside the
 * routes that reach it.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    /** @var array{User, Warehouse, Warehouse, ProductVariant} $fixtures */
    $fixtures = inTenantContext($this->tenant, function (): array {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        /*
        | One branch, two warehouses hanging off it — not two warehouses each conjuring
        | their own branch, which is what `Warehouse::factory()` does when left alone.
        |
        | It matters because `inventory.branches` is measured from live rows: a fixture
        | that quietly created a second branch would put the shop one slot further along
        | its standing capacity than the test says it is, and the branch assertions would
        | be off by one for a reason nowhere in the test.
        */
        $branch = Branch::factory()->default()->create();

        $from = Warehouse::factory()->create(['branch_id' => $branch->getKey(), 'name' => 'انبار مرکزی']);
        $to = Warehouse::factory()->create(['branch_id' => $branch->getKey(), 'name' => 'انبار ونک']);

        $product = Product::factory()->create(['name' => 'کابل شارژ', 'type' => 'standard']);
        $variant = ProductVariant::factory()->for($product)->create();

        app(StockLedger::class)->record(
            $variant->id, $from->id, 50, MovementType::Purchase, unitCost: 12_000_000
        );

        return [$owner, $from, $to, $variant];
    });

    [$this->owner, $this->from, $this->to, $this->variant] = $fixtures;
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * A draft حواله between the two fixture warehouses, opened through the real route.
 */
function openTransfer(): StockTransfer
{
    /** @var User $owner */
    $owner = test()->owner;
    /** @var Warehouse $from */
    $from = test()->from;
    /** @var Warehouse $to */
    $to = test()->to;
    /** @var string $url */
    $url = test()->url;
    /** @var Tenant $tenant */
    $tenant = test()->tenant;

    test()->actingAs($owner)->post($url.'/inventory/transfers', [
        'from_warehouse_id' => $from->id,
        'to_warehouse_id' => $to->id,
    ])->assertRedirect();

    /** @var StockTransfer $transfer */
    $transfer = inTenantContext($tenant, fn (): StockTransfer => StockTransfer::query()->latest('id')->firstOrFail());

    return $transfer;
}

function addTransferLine(StockTransfer $transfer, int $quantity): void
{
    /** @var User $owner */
    $owner = test()->owner;
    /** @var ProductVariant $variant */
    $variant = test()->variant;
    /** @var string $url */
    $url = test()->url;

    test()->actingAs($owner)->post($url.'/inventory/transfers/'.idOf($transfer).'/lines', [
        'product_variant_id' => $variant->id,
        'quantity' => $quantity,
    ])->assertRedirect();
}

/**
 * Send the van. This is the metered click, and the only one — everything above it is
 * paperwork the shop has not been charged for.
 *
 * Deliberately asserts nothing about the response: half these tests want a redirect with
 * no errors and the other half want the refusal, and a helper that asserted success could
 * not be used by the second half.
 *
 * @return TestResponse<Illuminate\Http\Response>
 */
function dispatchTransfer(StockTransfer $transfer): TestResponse
{
    /** @var User $owner */
    $owner = test()->owner;
    /** @var string $url */
    $url = test()->url;

    return test()->actingAs($owner)->post($url.'/inventory/transfers/'.idOf($transfer).'/dispatch');
}

/**
 * An open انبارگردانی on the source warehouse, blind as a real one is.
 */
function openCount(): StockCount
{
    /** @var User $owner */
    $owner = test()->owner;
    /** @var Warehouse $from */
    $from = test()->from;
    /** @var string $url */
    $url = test()->url;
    /** @var Tenant $tenant */
    $tenant = test()->tenant;

    test()->actingAs($owner)->post($url.'/inventory/counts', [
        'warehouse_id' => $from->id,
        'is_blind' => true,
    ])->assertRedirect();

    /** @var StockCount $count */
    $count = inTenantContext($tenant, fn (): StockCount => StockCount::query()->latest('id')->firstOrFail());

    return $count;
}

/**
 * Put the fixture variant on the sheet and write down what the shelf actually held.
 */
function countShelf(StockCount $count, int $found): void
{
    /** @var User $owner */
    $owner = test()->owner;
    /** @var ProductVariant $variant */
    $variant = test()->variant;
    /** @var string $url */
    $url = test()->url;
    /** @var Tenant $tenant */
    $tenant = test()->tenant;

    test()->actingAs($owner)->post($url.'/inventory/counts/'.idOf($count).'/lines', [
        'product_variant_id' => $variant->id,
    ])->assertRedirect();

    /** @var StockCountItem $item */
    $item = inTenantContext($tenant, fn (): StockCountItem => StockCountItem::query()
        ->where('stock_count_id', idOf($count))
        ->latest('id')
        ->firstOrFail());

    test()->actingAs($owner)
        ->put($url.'/inventory/counts/'.idOf($count).'/counted', ['counted' => [$item->id => $found]])
        ->assertRedirect();
}

/**
 * Close the sheet and write the differences. The metered click for a count.
 *
 * @return TestResponse<Illuminate\Http\Response>
 */
function applyCount(StockCount $count): TestResponse
{
    /** @var User $owner */
    $owner = test()->owner;
    /** @var string $url */
    $url = test()->url;

    return test()->actingAs($owner)->post($url.'/inventory/counts/'.idOf($count).'/apply');
}

/**
 * @return TestResponse<Illuminate\Http\Response>
 */
function addBranch(string $code): TestResponse
{
    /** @var User $owner */
    $owner = test()->owner;
    /** @var string $url */
    $url = test()->url;

    return test()->actingAs($owner)->post($url.'/branches', [
        'name' => 'شعبهٔ '.$code,
        'code' => $code,
    ]);
}

/**
 * Shut a branch down. There is no delete route — a branch is referenced by every document
 * it ever issued — so this is the whole of what "giving the slot back" can mean, and it is
 * the same `is_active` flag the metric's `measure:` closure counts.
 *
 * @return TestResponse<Illuminate\Http\Response>
 */
function closeBranch(Branch $branch): TestResponse
{
    /** @var User $owner */
    $owner = test()->owner;
    /** @var string $url */
    $url = test()->url;

    return test()->actingAs($owner)->put($url.'/branches/'.idOf($branch), [
        'name' => $branch->name,
        'code' => $branch->code,
        'is_active' => false,
    ]);
}

/**
 * What the branch meter says right now.
 *
 * Read through `check()` rather than by counting rows in the test, so this asserts the
 * metric's own `measure:` closure — the thing production actually consults — instead of a
 * second implementation of it that could agree with the shop while both were wrong.
 */
function branchesInUse(): int
{
    /** @var Tenant $tenant */
    $tenant = test()->tenant;

    /** @var int $used */
    $used = inTenantContext($tenant, fn (): int => app(QuotaGuard::class)->check('inventory.branches')->used);

    return $used;
}

/**
 * What the refusal left in the session for the shell to render.
 *
 * Prefixed with the module, and so is its sibling below. A `function` in a Pest file is a
 * plain global one — the whole suite loads into a single process — so a name as obvious as
 * `blockPayload()` is a name every module's enforcement-site test would reach for, and the
 * second file to declare it does not fail as a collision but as a fatal on an unrelated
 * module's run.
 *
 * @return array<string, mixed>
 */
function inventoryRefusal(): array
{
    /** @var array<string, mixed> $block */
    $block = session('quota_block') ?? [];

    return $block;
}

/**
 * The rung the refusal aims its upgrade button at.
 *
 * Read through its own shaped annotation rather than off `inventoryRefusal()`, because the
 * payload's values are honestly `mixed` — the block carries strings, ints, nulls and a
 * nested money array — and reaching two levels into `mixed` is exactly what Larastan's
 * `checkExplicitMixed` is set to refuse.
 */
function inventoryRefusalPlan(): ?string
{
    /** @var array{next_plan?: array{code?: string}} $block */
    $block = session('quota_block') ?? [];

    return $block['next_plan']['code'] ?? null;
}

/* ------------------------------------------------------------- transfers -- */

it('spends one transfer credit when the goods leave, and nothing before that', function (): void {
    $transfer = openTransfer();
    addTransferLine($transfer, 4);

    // A draft حواله with lines on it is still just a plan. The stock has not moved, so
    // neither has the meter — and `quotaRowExists` rather than `quotaUsed` is the honest
    // assertion, because a row reading zero would already be a charge waiting to happen.
    expect(quotaRowExists($this->tenant, 'inventory.transfers'))->toBeFalse();

    dispatchTransfer($transfer)->assertSessionHasNoErrors()->assertRedirect();

    expect(quotaUsed($this->tenant, 'inventory.transfers'))->toBe(1);
});

it('refuses the dispatch that would cross the ceiling, and the stock stays put', function (): void {
    capQuota($this->tenant, 'inventory.transfers', 1);

    $first = openTransfer();
    addTransferLine($first, 4);
    dispatchTransfer($first)->assertSessionHasNoErrors();

    $second = openTransfer();
    addTransferLine($second, 5);

    // The second one is the whole test. A shop at its cap must be *told* — see CLAUDE.md
    // on the operator pressing submit twice with a customer at the counter — and the goods
    // must still be where the warehousekeeper left them.
    dispatchTransfer($second)->assertSessionHasErrors('quota');

    inTenantContext($this->tenant, function () use ($second): void {
        /** @var ProductVariant $variant */
        $variant = $this->variant;
        /** @var Warehouse $from */
        $from = $this->from;

        expect($second->refresh()->isDraft())->toBeTrue()
            // Fifty in, four out on the dispatch that succeeded. The refused five never
            // left the shelf, which is the claim that matters: a refusal that had already
            // written the movement would have the shop hunting for stock that is on paper
            // in a van nobody sent.
            ->and(app(StockLedger::class)->onHand($variant->id, $from->id))->toBe(46);
    });

    expect(quotaUsed($this->tenant, 'inventory.transfers'))->toBe(1);
});

it('hands the warehousekeeper something to render, not just an error string', function (): void {
    capQuota($this->tenant, 'inventory.transfers', 0);

    $transfer = openTransfer();
    addTransferLine($transfer, 1);
    dispatchTransfer($transfer);

    $block = inventoryRefusal();

    // These are the keys `quota-block.tsx` reads. A refusal that reached the browser
    // without them would render an empty card, which is worse than a 500 because nobody
    // would report it.
    expect($block)->toHaveKeys(['metric', 'label', 'message', 'used', 'limit', 'resets_at', 'next_plan'])
        ->and($block['metric'])->toBe('inventory.transfers')
        // Persian, not the exception's English. `TransferController::dispatch()` wraps the
        // service in `catch (RuntimeException)` and turns whatever it catches into a
        // field-level message — which is exactly the arm that swallowed the block on a
        // dozen screens until `QuotaExceeded` stopped extending `RuntimeException`. This
        // assertion is the tripwire for that regression on this route.
        ->and($block['message'])->toBeString()->not->toContain('Quota exceeded')
        ->and(inventoryRefusalPlan())->toBe('enterprise');
});

it('spends nothing when the dispatch fails inside the transaction', function (): void {
    $empty = openTransfer();

    // `TransferService::dispatch()` consumes the credit and *then* discovers there are no
    // lines, deliberately — the credit is claimed before any work so two tills cannot both
    // pass the check. Which means the rollback is the only thing standing between a shop
    // and being charged for a حواله it never sent.
    dispatchTransfer($empty)->assertSessionHasErrors('dispatch');

    expect(quotaRowExists($this->tenant, 'inventory.transfers'))->toBeFalse();

    inTenantContext($this->tenant, function () use ($empty): void {
        expect($empty->refresh()->isDraft())->toBeTrue();
    });
});

/* ---------------------------------------------------------- stock counts -- */

it('spends one count credit when the sheet is applied, and nothing while it is open', function (): void {
    $count = openCount();
    countShelf($count, 47);

    // Walking the shelves is free. A shop that had to spend a credit to open a clipboard
    // would count once a year instead of once a month, which is the opposite of what
    // انبارگردانی is for.
    expect(quotaRowExists($this->tenant, 'inventory.stock_counts'))->toBeFalse();

    applyCount($count)->assertSessionHasNoErrors()->assertRedirect();

    expect(quotaUsed($this->tenant, 'inventory.stock_counts'))->toBe(1);

    inTenantContext($this->tenant, function () use ($count): void {
        /** @var ProductVariant $variant */
        $variant = $this->variant;
        /** @var Warehouse $from */
        $from = $this->from;

        expect($count->refresh()->status)->toBe(StockCount::STATUS_APPLIED)
            ->and(app(StockLedger::class)->onHand($variant->id, $from->id))->toBe(47);
    });
});

it('refuses to apply a count past the ceiling and leaves the sheet open', function (): void {
    capQuota($this->tenant, 'inventory.stock_counts', 0);

    $count = openCount();
    countShelf($count, 47);

    applyCount($count)->assertSessionHasErrors('quota');

    $block = inventoryRefusal();

    expect($block['metric'] ?? null)->toBe('inventory.stock_counts')
        ->and($block['message'] ?? null)->toBeString()->not->toContain('Quota exceeded');

    inTenantContext($this->tenant, function () use ($count): void {
        /** @var ProductVariant $variant */
        $variant = $this->variant;

        // Still open, and no adjustment written. A refusal that had left the sheet closed
        // would have destroyed the count — the numbers are on the shelf, not in the
        // software, and nobody is walking the aisles a second time.
        expect($count->refresh()->isOpen())->toBeTrue()
            ->and(StockMovement::query()
                ->where('product_variant_id', $variant->id)
                ->where('type', MovementType::Count)
                ->count())->toBe(0);
    });
});

/* ----------------------------------------------- one credit per act, only -- */

it('meters a حواله and an انبارگردانی against separate credits', function (): void {
    // Both at one, so each act uses up its own credit entirely. If either drew on the
    // other's, the second act in this test would be refused.
    capQuota($this->tenant, 'inventory.transfers', 1);
    capQuota($this->tenant, 'inventory.stock_counts', 1);

    $transfer = openTransfer();
    addTransferLine($transfer, 3);
    dispatchTransfer($transfer)->assertSessionHasNoErrors();

    expect(quotaUsed($this->tenant, 'inventory.transfers'))->toBe(1)
        ->and(quotaRowExists($this->tenant, 'inventory.stock_counts'))->toBeFalse();

    $count = openCount();
    countShelf($count, 40);
    applyCount($count)->assertSessionHasNoErrors();

    // A shop that moved stock this month has not thereby used up its right to count it.
    // They are sold separately on the pricing page and they are charged separately here.
    expect(quotaUsed($this->tenant, 'inventory.stock_counts'))->toBe(1)
        ->and(quotaUsed($this->tenant, 'inventory.transfers'))->toBe(1);
});

/* -------------------------------------------------------------- branches -- */

it('measures branches from live rows rather than from a counter', function (): void {
    // One branch exists — the fixture's — and the cap allows two.
    capQuota($this->tenant, 'inventory.branches', 2);

    expect(branchesInUse())->toBe(1);

    addBranch('KRJ')->assertSessionHasNoErrors()->assertRedirect();

    expect(branchesInUse())->toBe(2)
        // The claim that makes this metric different in kind: a standing capacity keeps no
        // `usage_counters` row at all, so there is nothing to reset, nothing to carry into
        // next month, and no way for a counter and the shop's actual structure to drift
        // apart. `quotaUsed` would read zero here and mean nothing.
        ->and(quotaRowExists($this->tenant, 'inventory.branches'))->toBeFalse();

    addBranch('SHZ')->assertSessionHasErrors('quota');

    inTenantContext($this->tenant, fn () => expect(Branch::query()->count())->toBe(2));
    expect(branchesInUse())->toBe(2);
});

it('gives the slot back when a branch is closed', function (): void {
    capQuota($this->tenant, 'inventory.branches', 2);

    addBranch('KRJ')->assertSessionHasNoErrors();
    addBranch('SHZ')->assertSessionHasErrors('quota');

    /** @var Branch $karaj */
    $karaj = inTenantContext(
        $this->tenant,
        fn (): Branch => Branch::query()->where('code', 'KRJ')->firstOrFail()
    );

    closeBranch($karaj)->assertSessionHasNoErrors()->assertRedirect();

    // This is the whole reason a capacity is measured instead of counted. A shop that
    // opened a branch in Karaj, closed it in Mehr and wanted one in Shiraz in Aban would,
    // on a counted metric, be paying for a shopfront it no longer has — for ever, since a
    // Total window never resets. The row is still there (documents reference it); the
    // *slot* is not.
    expect(branchesInUse())->toBe(1);

    addBranch('SHZ')->assertSessionHasNoErrors()->assertRedirect();

    expect(branchesInUse())->toBe(2);
    inTenantContext($this->tenant, fn () => expect(Branch::query()->where('is_active', true)->count())->toBe(2));
});

it('does not promise a refill it cannot give, for a capacity that never refills', function (): void {
    capQuota($this->tenant, 'inventory.branches', 1);

    addBranch('KRJ')->assertSessionHasErrors('quota');

    $block = inventoryRefusal();

    expect($block)->toHaveKeys(['metric', 'label', 'message', 'used', 'limit', 'resets_at', 'next_plan'])
        ->and($block['metric'])->toBe('inventory.branches')
        ->and($block['used'])->toBe(1)
        ->and($block['limit'])->toBe(1)
        // Null, and it has to be: `PeriodClock::resetsAt()` returns nothing for a Total
        // window because waiting does not help. A date here would tell a shopkeeper to
        // come back on the first of the month for a slot that will never appear, which is
        // a worse answer than no answer.
        ->and($block['resets_at'])->toBeNull()
        ->and($block['message'])->toBeString()->not->toContain('Quota exceeded')
        // The upgrade has to be aimed at a rung that actually fits — «سازمانی» is the one
        // with unlimited branches. Sending them to a plan that would refuse them again is
        // how an upsell becomes a refund.
        ->and(inventoryRefusalPlan())->toBe('enterprise');
});
