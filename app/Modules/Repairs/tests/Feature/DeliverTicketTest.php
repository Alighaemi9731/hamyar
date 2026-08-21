<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\Party;
use App\Modules\CRM\Services\LedgerService;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Inventory\Services\StockReservations;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Repairs\Enums\TicketStatus;
use App\Modules\Repairs\Models\RepairTicket;
use App\Modules\Repairs\Services\DeliverTicket;
use App\Modules\Repairs\Services\TicketIntake;
use App\Modules\Repairs\Services\TicketParts;
use App\Modules\Repairs\Services\TicketStateMachine;
use App\Modules\Sales\Enums\InvoiceStatus;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\ProfitEngine;
use App\Support\Files\AttachmentStore;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;

/**
 * Handing the device back, and the bill going through Sales rather than around it.
 *
 * The test that matters most is the double-deduction one: a part billed on a delivery
 * invoice must not leave the shelf a second time.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    $this->tenant->forceFill(['settings' => ['repairs' => ['approval_cap' => 999_999_999]]])->save();
    app(TenantContext::class)->forget();

    /** @var array{User, Warehouse, ProductVariant, Account, Party} $fixtures */
    $fixtures = inTenantContext($this->tenant, function (): array {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        $warehouse = Warehouse::factory()->create(['is_sellable' => true, 'is_default' => true]);

        $product = Product::factory()->create(['name' => 'گلس', 'type' => 'standard']);
        $variant = ProductVariant::factory()->for($product)->create();

        app(StockLedger::class)->record($variant->id, $warehouse->id, 10, MovementType::Purchase, unitCost: 200_000);

        $cash = Account::factory()->create(['type' => Account::TYPE_CASH, 'is_default' => true]);
        Account::factory()->create(['type' => Account::TYPE_SALES]);
        Account::factory()->create(['type' => Account::TYPE_INVENTORY]);

        return [$owner, $warehouse, $variant, $cash, Party::factory()->create()];
    });

    [$this->owner, $this->warehouse, $this->variant, $this->cash, $this->party] = $fixtures;

    $this->inTenant = fn (Closure $callback): mixed => inTenantContext($this->tenant, $callback);
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * A ticket that is ready, with one part fitted at 900,000 rial.
 */
function readyTicket(int $prepaid = 0): RepairTicket
{
    /** @var Tenant $tenant */
    $tenant = test()->tenant;

    /** @var Warehouse $warehouse */
    $warehouse = test()->warehouse;
    /** @var Party $party */
    $party = test()->party;
    /** @var User $owner */
    $owner = test()->owner;
    /** @var ProductVariant $variant */
    $variant = test()->variant;

    /** @var RepairTicket $ticket */
    $ticket = inTenantContext($tenant, function () use ($prepaid, $warehouse, $party, $owner, $variant): RepairTicket {
        $ticket = app(TicketIntake::class)->take([
            'branch_id' => $warehouse->branch_id,
            'party_id' => $party->id,
            'device_model' => 'آیفون ۱۳',
            'reported_issue' => 'صفحه شکسته',
            'prepaid_amount' => $prepaid,
        ], $owner->id);

        $part = app(TicketParts::class)->reserve(
            $ticket, $variant->id, $warehouse->id, 1, 900_000, $owner->id,
        );

        app(TicketParts::class)->consume($part, $owner->id);

        $machine = app(TicketStateMachine::class);
        $machine->transition($ticket, TicketStatus::Repairing, $owner->id);
        $machine->transition($ticket->fresh() ?? $ticket, TicketStatus::Ready, $owner->id);

        return $ticket->fresh() ?? $ticket;
    });

    return $ticket;
}

/* ---------------------------------------------- THE DOUBLE DEDUCTION -- */

it('does not take the part off the shelf a second time when billing it', function (): void {
    $ticket = readyTicket();

    // One part fitted: ten purchased, one consumed.
    ($this->inTenant)(fn () => expect(
        app(StockLedger::class)->onHand($this->variant->id, $this->warehouse->id)
    )->toBe(9));

    ($this->inTenant)(function () use ($ticket): void {
        app(DeliverTicket::class)->deliver(
            $ticket,
            [['description' => 'دستمزد', 'amount' => 1_500_000]],
            [['method' => 'cash', 'amount' => 2_400_000, 'account_id' => $this->cash->id]],
            30,
            $this->owner->id,
        );
    });

    ($this->inTenant)(function (): void {
        // STILL nine. The part left the shelf as `repair_consume` when it was fitted;
        // billing it as a service line writes no second movement.
        expect(app(StockLedger::class)->onHand($this->variant->id, $this->warehouse->id))->toBe(9)
            ->and(StockMovement::query()->where('type', MovementType::Sale->value)->count())->toBe(0)
            ->and(StockMovement::query()->where('type', MovementType::RepairConsume->value)->count())->toBe(1);
    });
});

/* ------------------------------------------------ the bill is a real sale -- */

it('bills through Sales, with a real numbered invoice and a ledger entry', function (): void {
    $ticket = readyTicket();

    $invoice = ($this->inTenant)(fn () => app(DeliverTicket::class)->deliver(
        $ticket,
        [['description' => 'دستمزد تعویض صفحه', 'amount' => 1_500_000]],
        [['method' => 'cash', 'amount' => 2_400_000, 'account_id' => $this->cash->id]],
        30,
        $this->owner->id,
    ));

    ($this->inTenant)(function () use ($invoice, $ticket): void {
        $fresh = $invoice->fresh();

        // A number from the same counter the till uses, and a finalised invoice.
        expect($fresh?->number)->toBe('INV-000001')
            ->and($fresh?->status)->toBe(InvoiceStatus::Final)
            // 900,000 part + 1,500,000 labour.
            ->and($fresh?->total)->toBe(2_400_000)
            ->and($fresh?->paid_total)->toBe(2_400_000);

        // Every line is a service line — that is what keeps stock out of it.
        expect($fresh?->items()->where('is_service', true)->count())->toBe(2)
            ->and($fresh?->items()->whereNotNull('product_variant_id')->count())->toBe(0);

        // The money reached the cash box through the normal posting.
        expect((int) App\Modules\CRM\Models\LedgerEntry::query()
            ->where('account_id', $this->cash->id)->sum('debit'))->toBe(2_400_000);

        expect($ticket->fresh()?->status)->toBe(TicketStatus::Delivered)
            ->and($ticket->fresh()?->warranty_days)->toBe(30)
            ->and($ticket->fresh()?->delivered_at)->not->toBeNull();
    });
});

it('treats the intake prepayment as a payment, not a discount', function (): void {
    $ticket = readyTicket(prepaid: 500_000);

    $invoice = ($this->inTenant)(fn () => app(DeliverTicket::class)->deliver(
        $ticket,
        [['description' => 'دستمزد', 'amount' => 1_500_000]],
        [['method' => 'cash', 'amount' => 1_900_000, 'account_id' => $this->cash->id]],
        0,
        $this->owner->id,
    ));

    ($this->inTenant)(function () use ($invoice): void {
        $fresh = $invoice->fresh();

        // The customer paid full price, part of it earlier. A discount would understate
        // both the sale and the tax on it — the same distinction the trade-in makes.
        expect($fresh?->total)->toBe(2_400_000)
            ->and($fresh?->discount_amount)->toBe(0)
            ->and($fresh?->paid_total)->toBe(2_400_000)
            ->and($fresh?->payments()->count())->toBe(2);
    });
});

it('leaves the balance on the customer when they do not settle in full', function (): void {
    $ticket = readyTicket();

    ($this->inTenant)(function () use ($ticket): void {
        app(DeliverTicket::class)->deliver(
            $ticket,
            [['description' => 'دستمزد', 'amount' => 1_500_000]],
            [['method' => 'cash', 'amount' => 400_000, 'account_id' => $this->cash->id]],
            0,
            $this->owner->id,
        );

        // Straight through Sales' own credit path — no repair-specific ledger code.
        expect(app(LedgerService::class)->partyBalance($this->party))->toBe(2_000_000);
    });
});

/* --------------------------------------------------------- reservations -- */

it('lets go of parts that were never fitted', function (): void {
    $ticket = readyTicket();

    ($this->inTenant)(function () use ($ticket): void {
        // Planned but not used — an extra gلس the technician did not need.
        app(TicketParts::class)->reserve($ticket, $this->variant->id, $this->warehouse->id, 3, 900_000, $this->owner->id);

        expect(app(StockReservations::class)->available($this->variant->id, $this->warehouse->id))->toBe(6);

        app(DeliverTicket::class)->deliver(
            $ticket,
            [['description' => 'دستمزد', 'amount' => 1_000_000]],
            [['method' => 'cash', 'amount' => 1_900_000, 'account_id' => $this->cash->id]],
            0,
            $this->owner->id,
        );

        // The device is out of the door. A hold on a part nobody fitted would quietly
        // shrink what the till may sell for as long as the row survived.
        expect(app(StockReservations::class)->available($this->variant->id, $this->warehouse->id))->toBe(9);
    });
});

/* ------------------------------------------------------------- refusals -- */

it('refuses to deliver a device that is not ready', function (): void {
    /** @var RepairTicket $ticket */
    $ticket = ($this->inTenant)(fn () => app(TicketIntake::class)->take([
        'branch_id' => $this->warehouse->branch_id,
        'device_model' => 'گلکسی',
        'reported_issue' => 'باتری',
    ], $this->owner->id));

    ($this->inTenant)(fn () => expect(fn () => app(DeliverTicket::class)->deliver(
        $ticket,
        [['description' => 'دستمزد', 'amount' => 500_000]],
        [],
        0,
        $this->owner->id,
    ))->toThrow(RuntimeException::class, 'تحویل داده نمی‌شود'));
});

it('refuses to deliver the same device twice', function (): void {
    $ticket = readyTicket();

    ($this->inTenant)(function () use ($ticket): void {
        app(DeliverTicket::class)->deliver(
            $ticket,
            [['description' => 'دستمزد', 'amount' => 1_000_000]],
            [['method' => 'cash', 'amount' => 1_900_000, 'account_id' => $this->cash->id]],
            0,
            $this->owner->id,
        );

        expect(fn () => app(DeliverTicket::class)->deliver(
            $ticket->fresh() ?? $ticket,
            [['description' => 'دوباره', 'amount' => 1_000_000]],
            [],
            0,
            $this->owner->id,
        ))->toThrow(RuntimeException::class, 'قبلاً تحویل داده شده');

        // And exactly one invoice exists.
        expect(SalesInvoice::query()->count())->toBe(1);
    });
});

it('refuses a delivery with nothing to charge for', function (): void {
    /** @var RepairTicket $ticket */
    $ticket = ($this->inTenant)(function (): RepairTicket {
        $ticket = app(TicketIntake::class)->take([
            'branch_id' => $this->warehouse->branch_id,
            'device_model' => 'گلکسی',
            'reported_issue' => 'باتری',
        ], $this->owner->id);

        // Through diagnosing: `queued → ready` is not in the map, because a device
        // nobody has looked at cannot be finished.
        $machine = app(TicketStateMachine::class);
        $machine->transition($ticket, TicketStatus::Diagnosing, $this->owner->id);
        $machine->transition($ticket->fresh() ?? $ticket, TicketStatus::Ready, $this->owner->id);

        return $ticket->fresh() ?? $ticket;
    });

    ($this->inTenant)(fn () => expect(fn () => app(DeliverTicket::class)->deliver(
        $ticket, [], [], 0, $this->owner->id,
    ))->toThrow(RuntimeException::class, 'دست‌کم یک ردیف'));
});

/* -------------------------------------------------------------- http -- */

it('delivers through the form and lands on the invoice', function (): void {
    $ticket = readyTicket();

    $this->actingAs($this->owner)
        ->post($this->url.'/repairs/tickets/'.$ticket->id.'/deliver', [
            'unit' => 'rial',
            'warranty_days' => 30,
            'labour' => [['description' => 'دستمزد', 'amount' => 1_500_000]],
            'payments' => [['method' => 'cash', 'amount' => 2_400_000, 'account_id' => $this->cash->id]],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    ($this->inTenant)(fn () => expect($ticket->fresh()?->status)->toBe(TicketStatus::Delivered));
});

it('bills the customer once when two tills deliver the same device at once', function (): void {
    $ticket = readyTicket();

    ($this->inTenant)(function () use ($ticket): void {
        // Two separately loaded instances — exactly what two concurrent HTTP requests
        // hold after route-model binding. Both read `ready`, and before the lock was
        // added both passed the guard, both created an invoice and the customer was
        // charged twice. An adversarial review probe found this by doing precisely this.
        /** @var RepairTicket $first */
        $first = RepairTicket::query()->findOrFail($ticket->getKey());
        /** @var RepairTicket $second */
        $second = RepairTicket::query()->findOrFail($ticket->getKey());

        $delivery = app(DeliverTicket::class);

        $delivery->deliver(
            $first,
            [['description' => 'دستمزد', 'amount' => 1_500_000]],
            [['method' => 'cash', 'amount' => 2_400_000, 'account_id' => $this->cash->id]],
            0,
            $this->owner->id,
        );

        // The loser re-reads `delivered` under the lock and is refused.
        expect(fn () => $delivery->deliver(
            $second,
            [['description' => 'دستمزد', 'amount' => 1_500_000]],
            [['method' => 'cash', 'amount' => 2_400_000, 'account_id' => $this->cash->id]],
            0,
            $this->owner->id,
        ))->toThrow(RuntimeException::class, 'قبلاً تحویل داده شده');

        // One invoice, one cash posting, one history row. The three things that were
        // each doubled before.
        expect(SalesInvoice::query()->count())->toBe(1)
            ->and((int) App\Modules\CRM\Models\LedgerEntry::query()
                ->where('account_id', $this->cash->id)->sum('debit'))->toBe(2_400_000)
            ->and($ticket->histories()->where('to_status', TicketStatus::Delivered->value)->count())->toBe(1);
    });
});

it('still delivers when the signature cannot be stored', function (): void {
    $ticket = readyTicket();

    // The storage layer is unavailable — the exact failure a missing S3 adapter
    // produced during the Phase 6 browser walk.
    $this->mock(AttachmentStore::class, function ($mock): void {
        $mock->shouldReceive('attach')->andThrow(new RuntimeException('disk unavailable'));
    });

    $response = $this->actingAs($this->owner)
        ->post($this->url.'/repairs/tickets/'.$ticket->id.'/deliver', [
            'unit' => 'rial',
            'warranty_days' => 30,
            'labour' => [['description' => 'دستمزد', 'amount' => 1_500_000]],
            'payments' => [['method' => 'cash', 'amount' => 2_400_000, 'account_id' => $this->cash->id]],
            'signature' => UploadedFile::fake()->image('signature.png'),
        ]);

    // By the time the signature is stored the device is handed over, the invoice is
    // numbered and the money is posted. A 500 here would tell the operator the delivery
    // failed when it did not — and they would press the button again.
    $response->assertRedirect()->assertSessionHas('warning');

    ($this->inTenant)(function () use ($ticket): void {
        expect($ticket->fresh()?->status)->toBe(TicketStatus::Delivered)
            ->and(SalesInvoice::query()->count())->toBe(1);
    });
});

it('will not let another shop deliver this device', function (): void {
    $ticket = readyTicket();

    $other = Tenant::factory()->withDomain()->create();
    subscribe($other, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($other);

    $intruder = inTenantContext($other, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Owner');

        return $user;
    });

    $this->actingAs($intruder)
        ->post(appUrl().'/repairs/tickets/'.$ticket->id.'/deliver', [
            'unit' => 'rial',
            'warranty_days' => 0,
            'labour' => [['description' => 'دستمزد', 'amount' => 100_000]],
        ])
        ->assertNotFound();
});

/* ------------------------------------------ THE COST OF A FITTED PART -- */

/*
| A repair bill is not pure profit, and the technician is not paid as if it were.
|
| Every line on a repair invoice is a service line, which is what stops the fitted part
| being deducted from stock twice. But finalisation snapshots cost by looking up a
| variant, and a service line has none — so the parts came through at cost zero. The
| screen the shop bought for 200,000 and billed at 900,000 read as 900,000 of margin.
|
| Two things break at once, and the second is worse than the first. The Z report and the
| profit engine overstate the day, which is a wrong number. Commission is computed from
| that margin, so the technician is silently paid a percentage of the customer's whole
| bill rather than of what the shop actually made — which is money out of the till, every
| repair, until somebody reconciles a year of payroll.
|
| Found by an adversarial review, not by the tests above: every one of them checked stock
| movements and totals, and none looked at `cost_snapshot`.
*/

it('carries the fitted part cost onto the invoice, so repair profit is margin not turnover', function (): void {
    $ticket = readyTicket();

    ($this->inTenant)(function () use ($ticket): void {
        $invoice = app(DeliverTicket::class)->deliver(
            $ticket,
            [['description' => 'دستمزد', 'amount' => 1_500_000]],
            [['method' => 'cash', 'amount' => 2_400_000, 'account_id' => $this->cash->id]],
            30,
            $this->owner->id,
        );

        $items = $invoice->refresh()->items;

        $part = $items->firstWhere('description', 'گلس');
        $labour = $items->firstWhere('description', 'دستمزد');

        // The part carries what it cost the shop, snapshotted when it was fitted.
        expect($part?->cost_snapshot)->toBe(200_000);

        // Labour costs the shop no goods. The technician's wage is an operating
        // expense, not a cost of what was sold.
        expect($labour?->cost_snapshot)->toBe(0);

        // 2,400,000 billed, 200,000 of it goods.
        $profit = app(ProfitEngine::class)->forInvoice($invoice->refresh());

        expect($profit['cost'])->toBe(200_000)
            ->and($profit['profit'])->toBe(2_200_000);
    });
});

it('pays commission on the repair margin, not on the whole bill', function (): void {
    $this->tenant->forceFill(['settings' => ['commission' => ['rate' => 5], 'repairs' => ['approval_cap' => 999_999_999]]])->save();
    app(TenantContext::class)->forget();

    $ticket = readyTicket();

    ($this->inTenant)(function () use ($ticket): void {
        $invoice = app(DeliverTicket::class)->deliver(
            $ticket,
            [['description' => 'دستمزد', 'amount' => 1_500_000]],
            [['method' => 'cash', 'amount' => 2_400_000, 'account_id' => $this->cash->id]],
            30,
            $this->owner->id,
        );

        // 5% of (2,400,000 − 200,000) = 110,000.
        //
        // Before the cost reached the line this was 120,000 — 5% of the full turnover.
        // Ten thousand rial a repair does not look like much until it is a year of them.
        expect($invoice->refresh()->commission_amount)->toBe(110_000);
    });
});
