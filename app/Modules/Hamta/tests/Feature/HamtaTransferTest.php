<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\Party;
use App\Modules\Hamta\Enums\ChecklistStep;
use App\Modules\Hamta\Enums\HamtaStatus;
use App\Modules\Hamta\Events\HamtaTransferCompleted;
use App\Modules\Hamta\Events\HamtaTransferPending;
use App\Modules\Hamta\Models\HamtaChecklistAnswer;
use App\Modules\Hamta\Services\HamtaRegistry;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\UnitCondition;
use App\Modules\Inventory\Events\UnitAcquired;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\FinaliseInvoice;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Event;

/**
 * HAMTA: recording, never verifying.
 *
 * ## The columns existed for seven phases with no writer
 *
 * `product_units.hamta_status` shipped in Phase 3 and nothing ever set it, so every device
 * in every shop read `not_required` — used ones included — and the warnings that depend on
 * it had nothing to depend on. `docs/testing.md` names the shape: *a feature with
 * enforcement but no write path is invisible*. These tests are the write path's first
 * coverage, so they start from the acquisition events rather than from a hand-set column.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = tenantUrl($this->tenant);

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    /** @var array{User, ProductVariant, Warehouse} $fixtures */
    $fixtures = app(TenantContext::class)->runFor($this->tenant, function (): array {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        $product = Product::factory()->create(['name' => 'iPhone 13', 'type' => 'serialized']);
        $variant = ProductVariant::factory()->create(['product_id' => $product->getKey()]);

        // A factory-made tenant has no provisioned branch or warehouse — `TenantProvisioned`
        // is dispatched by the provisioner, not by the factory — so the fixture makes its
        // own, exactly as the Sales and Cheques suites do.
        $warehouse = Warehouse::factory()->create(['is_sellable' => true, 'is_default' => true]);

        // Finalisation posts revenue to a sales account and refuses without one — the
        // ledger is where a sale becomes real, so there is no "just skip it" path.
        Account::factory()->create(['type' => Account::TYPE_CASH]);
        Account::factory()->create(['type' => Account::TYPE_SALES]);

        return [$owner, $variant, $warehouse];
    });

    [$this->owner, $this->variant, $this->warehouse] = $fixtures;

    $this->makeUnit = fn (UnitCondition $condition, string $imei): ProductUnit => app(TenantContext::class)
        ->runFor($this->tenant, fn (): ProductUnit => ProductUnit::query()->create([
            'product_variant_id' => $this->variant->getKey(),
            'warehouse_id' => $this->warehouse->getKey(),
            'imei1' => $imei,
            'condition' => $condition,
            'status' => 'in_stock',
            'cost' => 53_640_000,
        ]));
});

afterEach(function (): void {
    app(TenantContext::class)->forget();
});

/* --------------------------------------------------------- the status machine -- */

it('flags a used device as pending the moment it is acquired', function (): void {
    Event::fake([HamtaTransferPending::class]);

    $unit = ($this->makeUnit)(UnitCondition::Used, '350000000000001');

    inTenantContext($this->tenant, function () use ($unit): void {
        UnitAcquired::dispatch($unit);

        expect($unit->fresh()?->hamta_status)->toBe(HamtaStatus::Pending->value);
    });

    Event::assertDispatched(HamtaTransferPending::class, fn ($e): bool => $e->reason === 'acquired');
});

it('leaves a new device alone', function (): void {
    /*
    | The negative half, and it needs the positive one above beside it or it passes on a
    | world where the listener is not wired at all — the empty-world trap from
    | docs/testing.md §3.
    */
    $unit = ($this->makeUnit)(UnitCondition::New, '350000000000002');

    inTenantContext($this->tenant, function () use ($unit): void {
        UnitAcquired::dispatch($unit);

        expect($unit->fresh()?->hamta_status)->toBe(HamtaStatus::NotRequired->value);
    });
});

it('treats a refurbished device as owing a transfer', function (): void {
    $unit = ($this->makeUnit)(UnitCondition::Refurbished, '350000000000003');

    inTenantContext($this->tenant, function () use ($unit): void {
        UnitAcquired::dispatch($unit);

        // Whatever the shop did to it, the registry still has the previous owner's name
        // against that IMEI.
        expect($unit->fresh()?->hamta_status)->toBe(HamtaStatus::Pending->value);
    });
});

it('goes back to pending when a device that was transferred in is sold on', function (): void {
    /*
    | The case that makes `hamta_status` a statement about the CURRENT outstanding transfer
    | rather than about the device's history — and the one an implementation treating `done`
    | as terminal gets wrong. The second transfer is the one the customer walks out with.
    */
    $unit = ($this->makeUnit)(UnitCondition::Used, '350000000000004');

    inTenantContext($this->tenant, function () use ($unit): void {
        $registry = app(HamtaRegistry::class);

        // In: pending, then the shop completes it into its own name.
        $registry->markPending($unit, 'acquired');
        $registry->recordTransfer($unit, activationId: 'ACT-11223344');

        expect($unit->fresh()?->hamta_status)->toBe(HamtaStatus::Done->value);

        // Out: pending again.
        $registry->markPending($unit->fresh(), 'sold');

        $after = $unit->fresh();

        expect($after?->hamta_status)->toBe(HamtaStatus::Pending->value);

        // And the earlier transfer's evidence survives. Clearing it here would erase the
        // shop's record of the hand-over that brought the device in.
        expect($after?->hamta_activation_id)->toBe('ACT-11223344');
        expect($after?->hamta_transferred_at)->toBeNull();
    });
});

it('records a transfer without an activation id, because the SMS arrives later', function (): void {
    $unit = ($this->makeUnit)(UnitCondition::Used, '350000000000005');

    Event::fake([HamtaTransferCompleted::class]);

    inTenantContext($this->tenant, function () use ($unit): void {
        app(HamtaRegistry::class)->recordTransfer($unit, activationId: null, note: 'مشتری پیامک را بعداً می‌فرستد');

        $after = $unit->fresh();

        expect($after?->hamta_status)->toBe(HamtaStatus::Done->value)
            ->and($after?->hamta_activation_id)->toBeNull()
            ->and($after?->hamta_transferred_at)->not->toBeNull();
    });

    Event::assertDispatched(HamtaTransferCompleted::class);
});

it('does not let a later note erase an activation id recorded earlier', function (): void {
    $unit = ($this->makeUnit)(UnitCondition::Used, '350000000000006');

    inTenantContext($this->tenant, function () use ($unit): void {
        $registry = app(HamtaRegistry::class);

        $registry->recordTransfer($unit, activationId: 'ACT-99887766');
        $registry->recordTransfer($unit->fresh(), activationId: null, note: 'اصلاح توضیح');

        expect($unit->fresh()?->hamta_activation_id)->toBe('ACT-99887766');
    });
});

/* ------------------------------------------------------------ the sale listener -- */

it('flags a used handset when the invoice that sells it is finalised', function (): void {
    $unit = ($this->makeUnit)(UnitCondition::Used, '350000000000007');

    inTenantContext($this->tenant, function () use ($unit): void {
        // Cleared first, so the assertion below cannot pass on the acquisition flag.
        app(HamtaRegistry::class)->recordTransfer($unit, activationId: 'ACT-1');

        expect($unit->fresh()?->hamta_status)->toBe(HamtaStatus::Done->value);
    });

    /** @var SalesInvoice $invoice */
    $invoice = inTenantContext($this->tenant, function () use ($unit): SalesInvoice {
        $party = Party::factory()->create(['kind' => 'customer']);

        $invoice = SalesInvoice::query()->create([
            'branch_id' => $this->warehouse->branch_id,
            'party_id' => $party->getKey(),
            'status' => 'draft',
            'subtotal' => 60_000_000,
            'total' => 60_000_000,
        ]);

        $invoice->items()->create([
            'product_variant_id' => $this->variant->getKey(),
            'product_unit_id' => $unit->getKey(),
            'description' => 'iPhone 13 دست‌دوم',
            'quantity' => 1,
            'unit_price' => 60_000_000,
            'line_total' => 60_000_000,
        ]);

        return app(FinaliseInvoice::class)->finalise($invoice->refresh(), idOf($this->owner));
    });

    inTenantContext($this->tenant, function () use ($unit, $invoice): void {
        expect($invoice->status->value)->toBe('final');

        // The transfer that matters most: this one protects the CUSTOMER, who otherwise
        // finds their phone restricted months later with no idea why.
        expect($unit->fresh()?->hamta_status)->toBe(HamtaStatus::Pending->value);
    });
});

/* --------------------------------------------------------------- the checklist -- */

it('stores every answer with who gave it, and never updates one', function (): void {
    $unit = ($this->makeUnit)(UnitCondition::Used, '350000000000008');

    $this->actingAs($this->owner)
        ->post($this->url.'/hamta/'.$unit->getKey().'/checklist', [
            'answers' => [
                ChecklistStep::OwnerConfirmed->value => ['answer' => 'confirmed'],
                ChecklistStep::SmsAwaited->value => ['answer' => 'skipped', 'note' => 'مشتری عجله داشت'],
            ],
        ])
        ->assertSessionHasNoErrors();

    // A correction: the same step answered again.
    $this->actingAs($this->owner)
        ->post($this->url.'/hamta/'.$unit->getKey().'/checklist', [
            'answers' => [
                ChecklistStep::SmsAwaited->value => ['answer' => 'confirmed', 'note' => 'پیامک بعداً رسید'],
            ],
        ])
        ->assertSessionHasNoErrors();

    inTenantContext($this->tenant, function () use ($unit): void {
        $answers = HamtaChecklistAnswer::query()
            ->where('product_unit_id', $unit->getKey())
            ->orderBy('id')
            ->get();

        // Three rows, not two: the correction is a NEW row. Evidence that can be edited
        // afterwards proves only what somebody wanted it to say later.
        expect($answers)->toHaveCount(3);

        /** @var list<HamtaChecklistAnswer> $sms */
        $sms = $answers->where('step', ChecklistStep::SmsAwaited)->values()->all();

        expect($sms)->toHaveCount(2)
            ->and($sms[0]->answer)->toBe(HamtaChecklistAnswer::ANSWER_SKIPPED)
            ->and($sms[1]->answer)->toBe(HamtaChecklistAnswer::ANSWER_CONFIRMED);

        // Attribution IS the evidence. An answer with no name against it protects nobody.
        expect($answers->first()?->actor_id)->toBe($this->owner->getKey());

        // And the panel shows the latest per step.
        $latest = app(HamtaRegistry::class)->latestAnswers($unit);

        expect($latest[ChecklistStep::SmsAwaited->value]->answer)
            ->toBe(HamtaChecklistAnswer::ANSWER_CONFIRMED);
    });
});

it('accepts a checklist posted with no answers key at all', function (): void {
    /*
    | A `FormData` body cannot express an empty map — a salesperson who opens the checklist,
    | ticks nothing and saves sends no `answers` key. `present`/`required` would reject
    | exactly that ordinary case, and only a test that omits the key catches it.
    */
    $unit = ($this->makeUnit)(UnitCondition::Used, '350000000000009');

    $this->actingAs($this->owner)
        ->post($this->url.'/hamta/'.$unit->getKey().'/checklist', [])
        ->assertSessionHasNoErrors();
});

it('ignores an answer keyed to a step that does not exist', function (): void {
    $unit = ($this->makeUnit)(UnitCondition::Used, '350000000000010');

    $this->actingAs($this->owner)
        ->post($this->url.'/hamta/'.$unit->getKey().'/checklist', [
            'answers' => ['not_a_real_step' => ['answer' => 'confirmed']],
        ])
        ->assertSessionHasNoErrors();

    inTenantContext($this->tenant, function () use ($unit): void {
        expect(HamtaChecklistAnswer::query()->where('product_unit_id', $unit->getKey())->count())->toBe(0);
    });
});

/* -------------------------------------------------------------------- screens -- */

it('lists pending transfers and says there is no API', function (): void {
    $unit = ($this->makeUnit)(UnitCondition::Used, '350000000000011');

    inTenantContext($this->tenant, fn () => app(HamtaRegistry::class)->markPending($unit, 'acquired'));

    $this->actingAs($this->owner)
        ->get($this->url.'/hamta')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Hamta::Hamta/Pending')
            ->count('units', 1)
            ->where('units.0.imei', '350000000000011')
            ->etc());
});

it('serves the guide to anybody signed in, with no stock permission', function (): void {
    $assistant = app(TenantContext::class)->runFor($this->tenant, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Salesperson');

        return $user;
    });

    $this->actingAs($assistant)
        ->get($this->url.'/hamta/guide')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Hamta::Hamta/Guide')->etc());
});

it('refuses to record a transfer without the adjust permission', function (): void {
    $unit = ($this->makeUnit)(UnitCondition::Used, '350000000000012');

    $salesperson = app(TenantContext::class)->runFor($this->tenant, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Salesperson');

        return $user;
    });

    $this->actingAs($salesperson)
        ->post($this->url.'/hamta/'.$unit->getKey().'/transfer', ['activation_id' => 'ACT-X'])
        ->assertForbidden();
});

it('stores an activation id verbatim rather than validating a shape it cannot know', function (): void {
    $unit = ($this->makeUnit)(UnitCondition::Used, '350000000000013');

    // A shape nobody would design. Accepted on purpose: there is no published contract to
    // check against, and a rejected id sends a salesperson hunting a bug mid-sale.
    $this->actingAs($this->owner)
        ->post($this->url.'/hamta/'.$unit->getKey().'/transfer', ['activation_id' => 'همتا-۱۲۳/AB-99'])
        ->assertSessionHasNoErrors();

    inTenantContext($this->tenant, function () use ($unit): void {
        expect($unit->fresh()?->hamta_activation_id)->toBe('همتا-۱۲۳/AB-99');
    });
});

it('reopens a transfer without losing the checklist or the activation id', function (): void {
    $unit = ($this->makeUnit)(UnitCondition::Used, '350000000000014');

    $this->actingAs($this->owner)
        ->post($this->url.'/hamta/'.$unit->getKey().'/checklist', [
            'answers' => [ChecklistStep::OwnerConfirmed->value => ['answer' => 'confirmed']],
        ]);

    $this->actingAs($this->owner)
        ->post($this->url.'/hamta/'.$unit->getKey().'/transfer', ['activation_id' => 'ACT-KEEP']);

    $this->actingAs($this->owner)
        ->post($this->url.'/hamta/'.$unit->getKey().'/transfer', ['reopen' => true, 'note' => 'اشتباه ثبت شد'])
        ->assertSessionHasNoErrors();

    inTenantContext($this->tenant, function () use ($unit): void {
        $after = $unit->fresh();

        expect($after?->hamta_status)->toBe(HamtaStatus::Pending->value)
            ->and($after?->hamta_activation_id)->toBe('ACT-KEEP')
            ->and($after?->hamta_transferred_at)->toBeNull();

        expect(HamtaChecklistAnswer::query()->where('product_unit_id', $unit->getKey())->count())->toBe(1);
    });
});

/* ------------------------------------------------------------------ isolation -- */

it('never shows another shop’s pending transfers or accepts a write to one', function (): void {
    $mine = ($this->makeUnit)(UnitCondition::Used, '350000000000015');

    inTenantContext($this->tenant, fn () => app(HamtaRegistry::class)->markPending($mine, 'acquired'));

    $other = Tenant::factory()->withDomain()->create();
    subscribe($other, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($other);

    $neighbour = app(TenantContext::class)->runFor($other, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Owner');

        return $user;
    });

    // The positive half first: my shop really does have one pending device.
    $this->actingAs($this->owner)
        ->get($this->url.'/hamta')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->count('units', 1)->etc());

    // The neighbour sees none of it...
    $this->actingAs($neighbour)
        ->get(tenantUrl($other).'/hamta')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->count('units', 0)->etc());

    // ...and cannot reach the device by id. 404, not 403: a 403 confirms it exists.
    $this->actingAs($neighbour)
        ->post(tenantUrl($other).'/hamta/'.$mine->getKey().'/transfer', ['activation_id' => 'ACT-STEAL'])
        ->assertNotFound();

    inTenantContext($this->tenant, function () use ($mine): void {
        expect($mine->fresh()?->hamta_activation_id)->toBeNull();
    });
})->group('isolation');
