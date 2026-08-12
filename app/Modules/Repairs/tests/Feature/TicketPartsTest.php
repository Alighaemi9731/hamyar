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

/* ------------------------------------------------------ from the bench -- */

/*
| The service layer above is reachable from a screen, which is the difference between a
| feature and a class nobody can use. These drive the HTTP routes a technician's fingers
| actually hit.
*/

it('lets a technician search for a part and see what may be claimed', function (): void {
    $ticket = repairTicket();

    ($this->inTenant)(function () use ($ticket): void {
        app(TicketParts::class)->reserve($ticket, $this->variant->id, $this->warehouse->id, 4, 900_000, $this->owner->id);
    });

    $response = $this->actingAs($this->owner)
        ->getJson($this->url."/repairs/tickets/{$ticket->id}/parts/search?q=گلس");

    $response->assertOk();

    // Six, not ten. The number a bench needs is what is left after every other job's
    // holds — the same figure the till is quoted, for the same reason.
    expect($response->json('results.0.available'))->toBe(6)
        ->and($response->json('results.0.variant_id'))->toBe($this->variant->id);
});

it('keeps handsets out of the parts picker', function (): void {
    $ticket = repairTicket();

    ($this->inTenant)(function (): void {
        $phone = Product::factory()->create(['name' => 'گلس‌دار آیفون', 'type' => 'serialized']);
        ProductVariant::factory()->for($phone)->create(['barcode' => '6260000001111']);
    });

    $response = $this->actingAs($this->owner)
        ->getJson($this->url."/repairs/tickets/{$ticket->id}/parts/search?q=گلس");

    $response->assertOk();

    // A phone is stock the shop sells, not a component. Fitting one to somebody else's
    // handset would strand its IMEI history halfway through a repair.
    $names = array_column($response->json('results'), 'product_name');

    expect($names)->toContain('گلس محافظ صفحه')
        ->and($names)->not->toContain('گلس‌دار آیفون');
});

it('reserves a part from the ticket page and holds the stock', function (): void {
    $ticket = repairTicket();

    $this->actingAs($this->owner)
        ->post($this->url."/repairs/tickets/{$ticket->id}/parts", [
            'variant_id' => $this->variant->id,
            'quantity' => 2,
            'unit_price' => 900_000,
        ])
        ->assertRedirect();

    ($this->inTenant)(function () use ($ticket): void {
        expect(TicketPart::query()->where('repair_ticket_id', $ticket->id)->count())->toBe(1)
            // Nothing has moved off the shelf yet — a hold is not a movement.
            ->and(app(StockLedger::class)->onHand($this->variant->id, $this->warehouse->id))->toBe(10)
            ->and(app(StockReservations::class)->available($this->variant->id, $this->warehouse->id))->toBe(8);
    });
});

it('refuses more than the shop can spare, with a sentence the shopkeeper can act on', function (): void {
    $ticket = repairTicket();

    $response = $this->actingAs($this->owner)
        ->post($this->url."/repairs/tickets/{$ticket->id}/parts", [
            'variant_id' => $this->variant->id,
            'quantity' => 40,
            'unit_price' => 900_000,
        ]);

    // Not a 500. The message belongs beside the parts panel, where the person who typed
    // 40 is looking.
    $response->assertRedirect()->assertSessionHasErrors('parts');

    ($this->inTenant)(fn () => expect(TicketPart::query()->count())->toBe(0));
});

it('writes the one stock movement when the part is fitted', function (): void {
    $ticket = repairTicket();

    $this->actingAs($this->owner)->post($this->url."/repairs/tickets/{$ticket->id}/parts", [
        'variant_id' => $this->variant->id,
        'quantity' => 2,
        'unit_price' => 900_000,
    ]);

    /** @var TicketPart $part */
    $part = ($this->inTenant)(fn (): TicketPart => TicketPart::query()->firstOrFail());

    $this->actingAs($this->owner)
        ->post($this->url."/repairs/tickets/{$ticket->id}/parts/{$part->id}/consume")
        ->assertRedirect();

    ($this->inTenant)(function (): void {
        expect(app(StockLedger::class)->onHand($this->variant->id, $this->warehouse->id))->toBe(8)
            ->and(StockMovement::query()->where('type', MovementType::RepairConsume->value)->count())->toBe(1)
            // Cost snapshotted at the moment it left the shelf, not at reservation.
            ->and(TicketPart::query()->firstOrFail()->unit_cost)->toBe(200_000);
    });
});

it('puts a released part back on the shelf without a movement', function (): void {
    $ticket = repairTicket();

    $this->actingAs($this->owner)->post($this->url."/repairs/tickets/{$ticket->id}/parts", [
        'variant_id' => $this->variant->id,
        'quantity' => 3,
        'unit_price' => 900_000,
    ]);

    /** @var TicketPart $part */
    $part = ($this->inTenant)(fn (): TicketPart => TicketPart::query()->firstOrFail());

    $this->actingAs($this->owner)
        ->delete($this->url."/repairs/tickets/{$ticket->id}/parts/{$part->id}")
        ->assertRedirect();

    ($this->inTenant)(function (): void {
        expect(app(StockReservations::class)->available($this->variant->id, $this->warehouse->id))->toBe(10)
            // It never left the shelf. A pair of offsetting movements would be a lie
            // told twice.
            ->and(StockMovement::query()->where('type', MovementType::RepairConsume->value)->count())->toBe(0);
    });
});

it('will not consume a part through a ticket it does not belong to', function (): void {
    $ticket = repairTicket();
    $other = repairTicket();

    $this->actingAs($this->owner)->post($this->url."/repairs/tickets/{$ticket->id}/parts", [
        'variant_id' => $this->variant->id,
        'quantity' => 1,
        'unit_price' => 900_000,
    ]);

    /** @var TicketPart $part */
    $part = ($this->inTenant)(fn (): TicketPart => TicketPart::query()->firstOrFail());

    // The nested route reads as though ownership were checked. Nothing but the
    // controller checks it, so this is the test that says it does.
    $this->actingAs($this->owner)
        ->post($this->url."/repairs/tickets/{$other->id}/parts/{$part->id}/consume")
        ->assertNotFound();

    ($this->inTenant)(fn () => expect(TicketPart::query()->firstOrFail()->state)->toBe(TicketPart::STATE_RESERVED));
});

it('will not let another shop touch our parts', function (): void {
    $ticket = repairTicket();

    $this->actingAs($this->owner)->post($this->url."/repairs/tickets/{$ticket->id}/parts", [
        'variant_id' => $this->variant->id,
        'quantity' => 1,
        'unit_price' => 900_000,
    ]);

    $other = Tenant::factory()->withDomain()->create();
    subscribe($other, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($other);

    /** @var User $intruder */
    $intruder = inTenantContext($other, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Owner');

        return $user;
    });

    $this->actingAs($intruder)
        ->getJson(tenantUrl($other)."/repairs/tickets/{$ticket->id}/parts/search?q=گلس")
        ->assertNotFound();

    $this->actingAs($intruder)
        ->post(tenantUrl($other)."/repairs/tickets/{$ticket->id}/parts", [
            'variant_id' => $this->variant->id,
            'quantity' => 1,
        ])
        ->assertNotFound();
});

it('answers a search on stock whose average cost is not a round number, and sends no cost at all', function (): void {
    $ticket = repairTicket();

    // A second purchase at an awkward price. The weighted average is now 214,285.71…
    // rial — not a whole number of toman, which `Money::toArray` refuses outright.
    //
    // The first version of this endpoint formatted the cost into the response and 500'd
    // on real seeded data while every test passed, because the fixture happened to buy
    // at exactly 200,000. The figure is gone now for a better reason than the crash:
    // what the shop paid is gated behind `inventory.view_cost` at the till, and a parts
    // picker is not the place to hand it to everybody who can edit a ticket.
    ($this->inTenant)(function (): void {
        app(StockLedger::class)->record(
            $this->variant->id, $this->warehouse->id, 4, MovementType::Purchase, unitCost: 250_001,
        );
    });

    $response = $this->actingAs($this->owner)
        ->getJson($this->url."/repairs/tickets/{$ticket->id}/parts/search?q=گلس");

    $response->assertOk();

    expect($response->json('results.0.available'))->toBe(14)
        ->and($response->json('results.0'))->not->toHaveKey('cost');
});
