<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\CRM\Models\Account;
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
use App\Modules\Repairs\Models\RepairTicket;
use App\Modules\Repairs\Models\TicketPart;
use App\Modules\Repairs\Services\TicketIntake;
use App\Modules\Repairs\Services\TicketParts;
use App\Support\Tenancy\TenantContext;

/**
 * Parts, and the cross-module race that matters.
 *
 * The headline test is the till one: a screen protector reserved for a repair must be
 * invisible to the POS. Everything else here is the machinery that makes that true.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = tenantUrl($this->tenant);

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    /** @var array{User, Warehouse, ProductVariant, Account} $fixtures */
    $fixtures = inTenantContext($this->tenant, function (): array {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        $warehouse = Warehouse::factory()->create(['is_sellable' => true, 'is_default' => true]);

        $product = Product::factory()->create(['name' => 'گلس محافظ صفحه', 'type' => 'standard']);
        $variant = ProductVariant::factory()->for($product)->create(['barcode' => '6260000009999']);

        // Ten on the shelf at 200,000 rial each.
        app(StockLedger::class)->record($variant->id, $warehouse->id, 10, MovementType::Purchase, unitCost: 200_000);

        $cash = Account::factory()->create(['type' => Account::TYPE_CASH, 'is_default' => true]);
        Account::factory()->create(['type' => Account::TYPE_SALES]);

        return [$owner, $warehouse, $variant, $cash];
    });

    [$this->owner, $this->warehouse, $this->variant, $this->cash] = $fixtures;

    $this->inTenant = fn (Closure $callback): mixed => inTenantContext($this->tenant, $callback);
});

afterEach(fn () => app(TenantContext::class)->forget());

function repairTicket(): RepairTicket
{
    /** @var Tenant $tenant */
    $tenant = test()->tenant;
    /** @var Warehouse $warehouse */
    $warehouse = test()->warehouse;
    /** @var User $owner */
    $owner = test()->owner;

    /** @var RepairTicket $ticket */
    $ticket = inTenantContext($tenant, fn (): RepairTicket => app(TicketIntake::class)->take([
        'branch_id' => $warehouse->branch_id,
        'device_model' => 'آیفون ۱۳',
        'reported_issue' => 'صفحه شکسته',
    ], $owner->id));

    return $ticket;
}

/* ------------------------------------------------ THE CROSS-MODULE RACE -- */

it('hides a reserved part from the till', function (): void {
    $ticket = repairTicket();

    ($this->inTenant)(function () use ($ticket): void {
        app(TicketParts::class)->reserve($ticket, $this->variant->id, $this->warehouse->id, 4, 900_000, $this->owner->id);
    });

    // The shelf still holds ten — nothing has physically moved, and a stock count would
    // find ten.
    ($this->inTenant)(fn () => expect(
        app(StockLedger::class)->onHand($this->variant->id, $this->warehouse->id)
    )->toBe(10));

    // But the till may only promise six. This is the whole point: a POS quoting on-hand
    // would sell the repair's four out from under the bench, and the technician opens
    // the drawer to a gap.
    $response = $this->actingAs($this->owner)
        ->getJson($this->url.'/sales/pos/scan?code=6260000009999');

    $response->assertSuccessful();

    expect($response->json('results.0.on_hand'))->toBe(6);
});

it('gives the part back to the till when the job is cancelled', function (): void {
    $ticket = repairTicket();

    $part = ($this->inTenant)(fn () => app(TicketParts::class)->reserve(
        $ticket, $this->variant->id, $this->warehouse->id, 4, 900_000, $this->owner->id,
    ));

    ($this->inTenant)(fn () => app(TicketParts::class)->release($part, $this->owner->id));

    $response = $this->actingAs($this->owner)
        ->getJson($this->url.'/sales/pos/scan?code=6260000009999');

    expect($response->json('results.0.on_hand'))->toBe(10);

    // And NO stock movement was written in either direction. The part never left the
    // shelf; a pair of offsetting movements would be a lie told twice.
    ($this->inTenant)(fn () => expect(
        StockMovement::query()->where('type', MovementType::RepairConsume->value)->count()
    )->toBe(0));
});

/* -------------------------------------------------------- the three states -- */

it('writes the stock movement only when the part is actually fitted', function (): void {
    $ticket = repairTicket();

    $part = ($this->inTenant)(fn () => app(TicketParts::class)->reserve(
        $ticket, $this->variant->id, $this->warehouse->id, 2, 900_000, $this->owner->id,
    ));

    // Reserved: no movement yet.
    ($this->inTenant)(function (): void {
        expect(StockMovement::query()->where('type', MovementType::RepairConsume->value)->count())->toBe(0)
            ->and(app(StockLedger::class)->onHand($this->variant->id, $this->warehouse->id))->toBe(10);
    });

    ($this->inTenant)(fn () => app(TicketParts::class)->consume($part, $this->owner->id));

    ($this->inTenant)(function () use ($part): void {
        // Consumed: exactly one movement, and the shelf is genuinely lighter.
        expect(StockMovement::query()->where('type', MovementType::RepairConsume->value)->count())->toBe(1)
            ->and(app(StockLedger::class)->onHand($this->variant->id, $this->warehouse->id))->toBe(8)
            // The hold is gone, so available and on-hand agree again.
            ->and(app(StockReservations::class)->available($this->variant->id, $this->warehouse->id))->toBe(8);

        expect($part->fresh()?->state)->toBe(TicketPart::STATE_CONSUMED);
    });
});

it('snapshots what the part cost on the day it was fitted', function (): void {
    $ticket = repairTicket();

    $part = ($this->inTenant)(fn () => app(TicketParts::class)->reserve(
        $ticket, $this->variant->id, $this->warehouse->id, 2, 900_000, $this->owner->id,
    ));

    ($this->inTenant)(function (): void {
        // A later delivery at a much higher price moves the weighted average — Iranian
        // inflation, in one line.
        app(StockLedger::class)->record(
            $this->variant->id, $this->warehouse->id, 10, MovementType::Purchase, unitCost: 400_000,
        );
    });

    ($this->inTenant)(fn () => app(TicketParts::class)->consume($part, $this->owner->id));

    ($this->inTenant)(function () use ($part, $ticket): void {
        // 10 × 200,000 + 10 × 400,000 over 20 units = 300,000 each.
        expect($part->fresh()?->unit_cost)->toBe(300_000);

        $totals = app(TicketParts::class)->totals($ticket);

        // Two parts at 900,000 charged, 300,000 cost.
        expect($totals['revenue'])->toBe(1_800_000)
            ->and($totals['cost'])->toBe(600_000)
            ->and($totals['margin'])->toBe(1_200_000);
    });
});

it('counts only fitted parts towards the job total', function (): void {
    $ticket = repairTicket();

    ($this->inTenant)(function () use ($ticket): void {
        $parts = app(TicketParts::class);

        $fitted = $parts->reserve($ticket, $this->variant->id, $this->warehouse->id, 1, 900_000, $this->owner->id);
        $parts->reserve($ticket, $this->variant->id, $this->warehouse->id, 3, 900_000, $this->owner->id);

        $parts->consume($fitted, $this->owner->id);

        // A reserved part may still go back on the shelf. Counting it would quote a
        // profit on work that has not happened.
        expect($parts->totals($ticket)['revenue'])->toBe(900_000);
    });
});

/* --------------------------------------------------------------- limits -- */

it('will not reserve more than the shop can spare', function (): void {
    $ticket = repairTicket();

    ($this->inTenant)(function () use ($ticket): void {
        $parts = app(TicketParts::class);

        $parts->reserve($ticket, $this->variant->id, $this->warehouse->id, 8, 900_000, $this->owner->id);

        // Two left. Asking for three is refused with the figure that is actually free.
        expect(fn () => $parts->reserve($ticket, $this->variant->id, $this->warehouse->id, 3, 900_000))
            ->toThrow(RuntimeException::class, '2 عدد در دسترس است');
    });
});

it('will not let two repairs both claim the last part', function (): void {
    $first = repairTicket();
    $second = repairTicket();

    ($this->inTenant)(function () use ($first, $second): void {
        $parts = app(TicketParts::class);

        $parts->reserve($first, $this->variant->id, $this->warehouse->id, 10, 900_000, $this->owner->id);

        // The whole shelf is spoken for. The second bench gets a clean refusal rather
        // than a promise the shop cannot keep.
        expect(fn () => $parts->reserve($second, $this->variant->id, $this->warehouse->id, 1, 900_000))
            ->toThrow(RuntimeException::class, '0 عدد در دسترس است');
    });
});

it('refuses to consume or release a part twice', function (): void {
    $ticket = repairTicket();

    $part = ($this->inTenant)(fn () => app(TicketParts::class)->reserve(
        $ticket, $this->variant->id, $this->warehouse->id, 1, 900_000, $this->owner->id,
    ));

    ($this->inTenant)(function () use ($part): void {
        $parts = app(TicketParts::class);

        $parts->consume($part, $this->owner->id);

        expect(fn () => $parts->consume($part->fresh() ?? $part))
            ->toThrow(RuntimeException::class, 'قبلاً مصرف یا برگشت');

        expect(fn () => $parts->release($part->fresh() ?? $part))
            ->toThrow(RuntimeException::class, 'قبلاً مصرف یا برگشت');
    });
});

it('lets go of every unfitted part when a job is abandoned mid-repair', function (): void {
    $ticket = repairTicket();

    ($this->inTenant)(function () use ($ticket): void {
        $parts = app(TicketParts::class);

        $fitted = $parts->reserve($ticket, $this->variant->id, $this->warehouse->id, 2, 900_000, $this->owner->id);
        $parts->reserve($ticket, $this->variant->id, $this->warehouse->id, 3, 900_000, $this->owner->id);
        $parts->consume($fitted, $this->owner->id);

        $released = $parts->releaseAll($ticket, $this->owner->id);

        expect($released)->toBe(1);

        // The fitted pair stays consumed — it is inside the device and the customer is
        // paying for it. Only the unfitted hold comes off.
        expect(app(StockReservations::class)->available($this->variant->id, $this->warehouse->id))->toBe(8)
            ->and(app(StockLedger::class)->onHand($this->variant->id, $this->warehouse->id))->toBe(8);
    });
});

/* ------------------------------------------------------------- isolation -- */

it('does not let one shop reservations affect another shop till', function (): void {
    $ticket = repairTicket();

    ($this->inTenant)(fn () => app(TicketParts::class)->reserve(
        $ticket, $this->variant->id, $this->warehouse->id, 10, 900_000, $this->owner->id,
    ));

    $other = Tenant::factory()->withDomain()->create();
    subscribe($other, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($other);

    inTenantContext($other, function (): void {
        $warehouse = Warehouse::factory()->create(['is_sellable' => true, 'is_default' => true]);

        $product = Product::factory()->create(['name' => 'گلس محافظ صفحه', 'type' => 'standard']);
        $variant = ProductVariant::factory()->for($product)->create(['barcode' => '6260000009999']);

        app(StockLedger::class)->record($variant->id, $warehouse->id, 10, MovementType::Purchase, unitCost: 200_000);

        // Their own ten, entirely unreserved — RLS confines the reservation query to the
        // shop that made it.
        expect(app(StockReservations::class)->available($variant->id, $warehouse->id))->toBe(10);
    });
});
