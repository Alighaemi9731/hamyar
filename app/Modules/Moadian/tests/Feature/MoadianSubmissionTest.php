<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\Party;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Moadian\Contracts\MoadianDriver;
use App\Modules\Moadian\Drivers\FakeMoadianDriver;
use App\Modules\Moadian\Jobs\SubmitInvoiceJob;
use App\Modules\Moadian\Models\MoadianInvoice;
use App\Modules\Moadian\Models\MoadianSetting;
use App\Modules\Moadian\Services\InvoiceMapper;
use App\Modules\Moadian\Services\SubmitInvoice;
use App\Modules\Platform\Models\Module;
use App\Modules\Platform\Models\SubscriptionAddon;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\FinaliseInvoice;
use App\Modules\Sales\Services\VoidInvoice;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Queue;

/**
 * Moadian: the adapter, with no provider behind it.
 *
 * ADR 0011 — no real intermediary ships for launch, so `FakeMoadianDriver` is the only
 * implementation and these tests are the module's entire safety net. That makes the driver
 * contract tests load-bearing rather than ceremonial: the queue, the inbox, the mapping and
 * the idempotent resend are all verified against the *shape* the interface promises, so the
 * real driver — whenever a customer asks for one — drops in underneath without any of it
 * moving.
 *
 * ## The disabled default is tested first, because it is the launch configuration
 *
 * `MOADIAN_ENABLED=false` for every plan. "Does nothing, writes nothing, surfaces nothing"
 * is a property a shop depends on, not an absence to be assumed.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();

    $subscription = subscribe($this->tenant, 'pro');

    /*
    | Moadian is `is_addonable`, not part of any plan — so a `pro` subscription does NOT
    | reach these routes. The add-on has to be bought, which is the same act a shop
    | performs, and `module:moadian` is what would 403 without it.
    */
    $this->buyMoadian = function (Tenant $tenant, $subscription): void {
        app(TenantContext::class)->runAsPlatform(function () use ($tenant, $subscription): void {
            $module = Module::query()->where('code', 'moadian')->firstOrFail();

            SubscriptionAddon::query()->create([
                'tenant_id' => $tenant->getKey(),
                'subscription_id' => $subscription->getKey(),
                'module_id' => $module->getKey(),
                'price' => 150_000,
                'starts_at' => now()->subDay(),
            ]);
        });
    };

    ($this->buyMoadian)($this->tenant, $subscription);

    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    /** @var array{User, ProductVariant, Warehouse, Party} $fixtures */
    $fixtures = app(TenantContext::class)->runFor($this->tenant, function (): array {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        $product = Product::factory()->create(['name' => 'iPhone 15', 'type' => 'standard']);
        $variant = ProductVariant::factory()->create(['product_id' => $product->getKey()]);

        // Negative stock allowed: this suite is about what reaches the tax authority, and
        // seeding purchase movements for every fixture invoice would test the stock ledger
        // over and over while proving nothing about the mapping.
        $warehouse = Warehouse::factory()->create([
            'is_sellable' => true,
            'is_default' => true,
            'allows_negative_stock' => true,
        ]);

        Account::factory()->create(['type' => Account::TYPE_CASH]);
        Account::factory()->create(['type' => Account::TYPE_SALES]);

        $party = Party::factory()->create([
            'name' => 'شرکت نمونه',
            'kind' => 'customer',
            'economic_code' => '411111111111',
            'national_id' => '10101010101',
        ]);

        return [$owner, $variant, $warehouse, $party];
    });

    [$this->owner, $this->variant, $this->warehouse, $this->party] = $fixtures;

    /*
    | Non-round money by default (docs/testing.md): 8,881,990 rial a line is a whole toman a
    | shop can charge, and 10% of it is NOT — which is precisely the case a mapper that
    | recomputes VAT gets wrong and a fixture priced at 10,000,000 could never expose.
    */
    $this->makeInvoice = function (?int $partyId = null, int $unitPrice = 8_881_990, int $quantity = 2): SalesInvoice {
        return app(TenantContext::class)->runFor($this->tenant, function () use ($partyId, $unitPrice, $quantity): SalesInvoice {
            $lineTotal = $unitPrice * $quantity;
            $vat = intdiv($lineTotal * 10, 100);
            $vat = intdiv($vat, 10) * 10; // ADR 0009: floored to a whole toman, per line.

            $invoice = SalesInvoice::query()->create([
                'branch_id' => $this->warehouse->branch_id,
                'party_id' => $partyId,
                'status' => 'draft',
                'subtotal' => $lineTotal - $vat,
                'vat_amount' => $vat,
                'total' => $lineTotal,
            ]);

            $invoice->items()->create([
                'product_variant_id' => $this->variant->getKey(),
                'description' => 'iPhone 15',
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'vat_rate' => 10,
                'vat_amount' => $vat,
                'line_total' => $lineTotal,
            ]);

            return $invoice->refresh();
        });
    };

    $this->enableModule = function (): void {
        config()->set('moadian.enabled', true);

        app(TenantContext::class)->runFor($this->tenant, function (): void {
            MoadianSetting::query()->updateOrCreate([], [
                'memory_id' => 'MEM-123',
                'economic_code' => '400000000000',
                'provider' => 'fake',
                'is_enabled' => true,
            ]);
        });
    };

    app(MoadianDriver::class)->reset();
});

afterEach(function (): void {
    app(TenantContext::class)->forget();
});

/* ------------------------------------------------------- disabled is the default -- */

it('submits nothing and writes nothing while the feature is off', function (): void {
    /*
    | The launch configuration for every shop (ADR 0011). A tax module that accumulates
    | `pending` rows or logs errors for a shop that never opted in is a support ticket every
    | morning — and, worse, a set of documents somebody will one day ask about.
    */
    expect(config()->boolean('moadian.enabled'))->toBeFalse();

    $invoice = ($this->makeInvoice)($this->party->getKey());

    inTenantContext($this->tenant, function () use ($invoice): void {
        app(FinaliseInvoice::class)->finalise($invoice->refresh(), idOf($this->owner));

        expect(MoadianInvoice::query()->count())->toBe(0);
    });

    /** @var FakeMoadianDriver $driver */
    $driver = app(MoadianDriver::class);

    expect($driver->sent())->toBeEmpty();
});

it('still submits nothing when the shop opted in but the platform has not', function (): void {
    // Two switches, and «چرا کار نمی‌کند؟» has to distinguish them. The platform flag is
    // the one that keeps a development machine from filing a real tax document.
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        MoadianSetting::query()->updateOrCreate([], ['provider' => 'fake', 'is_enabled' => true]);
    });

    $invoice = ($this->makeInvoice)($this->party->getKey());

    inTenantContext($this->tenant, function () use ($invoice): void {
        app(FinaliseInvoice::class)->finalise($invoice->refresh(), idOf($this->owner));

        expect(MoadianInvoice::query()->count())->toBe(0);
    });
});

/* ------------------------------------------------------------ the payload mapping -- */

it('reproduces the invoice’s stored VAT rather than recomputing it', function (): void {
    /*
    | ADR 0009's amendment, and the one mapping bug that would be invisible against tidy
    | fixtures. Per-line VAT was FLOORED to a whole toman at issue. Recomputing 10% of the
    | period total rounds once instead of once per line, and the difference accrues in the
    | shop's favour — the direction a tax authority notices.
    */
    $invoice = ($this->makeInvoice)($this->party->getKey());

    inTenantContext($this->tenant, function () use ($invoice): void {
        $payload = app(InvoiceMapper::class)->map($invoice, null);

        $lineTotal = 8_881_990 * 2;                       // 17,763,980
        $storedVat = intdiv(intdiv($lineTotal * 10, 100), 10) * 10;  // floored: 1,776,390

        expect($payload->lines[0]->vatAmount)->toBe($storedVat);

        // And explicitly NOT the naive recompute, which the fixture can tell apart precisely
        // because the price is a non-round number of toman.
        expect($payload->lines[0]->vatAmount)->not->toBe(intdiv($lineTotal * 10, 100));

        // Every figure integer rial, no floats anywhere near it (golden rule 2).
        expect($payload->total)->toBeInt()->toBe($lineTotal);
    });
});

it('maps a walk-in as the anonymous consumer rather than failing', function (): void {
    // A great many counter sales have no party. Refusing to map one would make the module
    // unusable in the shops it is for.
    $invoice = ($this->makeInvoice)(null);

    inTenantContext($this->tenant, function () use ($invoice): void {
        $payload = app(InvoiceMapper::class)->map($invoice, null);

        expect($payload->buyer['type'])->toBe('consumer')
            ->and($payload->buyer['name'])->toBeNull();
    });
});

it('distinguishes a business buyer from a person by the economic code', function (): void {
    $invoice = ($this->makeInvoice)($this->party->getKey());

    $personId = app(TenantContext::class)->runFor(
        $this->tenant,
        fn (): int => idOf(Party::factory()->create(['kind' => 'customer', 'economic_code' => null, 'national_id' => '2222222222'])),
    );

    $personal = ($this->makeInvoice)($personId);

    inTenantContext($this->tenant, function () use ($invoice, $personal): void {
        $mapper = app(InvoiceMapper::class);

        expect($mapper->map($invoice, null)->buyer['type'])->toBe('business')
            ->and($mapper->map($personal, null)->buyer['type'])->toBe('person');
    });
});

/* ---------------------------------------------------------- the driver contract -- */

it('accepts, and records the authority’s references', function (): void {
    ($this->enableModule)();

    $invoice = ($this->makeInvoice)($this->party->getKey());

    inTenantContext($this->tenant, function () use ($invoice): void {
        $submission = app(SubmitInvoice::class)->enqueue($invoice);

        // Never null here: the module is enabled by `enableModule()` above.
        assert($submission instanceof MoadianInvoice);

        expect($submission)->not->toBeNull();

        app(SubmitInvoice::class)->send($submission);

        $after = $submission->fresh();

        expect($after?->status)->toBe(MoadianInvoice::STATUS_ACCEPTED)
            ->and($after?->reference_number)->not->toBeNull()
            ->and($after?->tax_id)->not->toBeNull()
            ->and($after?->confirmed_at)->not->toBeNull();
    });
});

it('records a rejection with its Persian reason and does not retry it', function (): void {
    /*
    | A rejection is an ANSWER, not a failure: the authority received the document and said
    | no. Retrying it unchanged gets an identical no, so `send()` returns normally and the
    | queue never sees an exception — the inbox is what picks it up.
    */
    ($this->enableModule)();

    /** @var FakeMoadianDriver $driver */
    $driver = app(MoadianDriver::class);
    $driver->rejectNext('E-100', 'شناسهٔ اقتصادی خریدار نامعتبر است.');

    $invoice = ($this->makeInvoice)($this->party->getKey());

    inTenantContext($this->tenant, function () use ($invoice): void {
        $submission = app(SubmitInvoice::class)->enqueue($invoice);

        // Never null here: the module is enabled by `enableModule()` above.
        assert($submission instanceof MoadianInvoice);

        // Returns normally — no exception for the queue to retry on.
        app(SubmitInvoice::class)->send($submission);

        $after = $submission->fresh();

        expect($after?->status)->toBe(MoadianInvoice::STATUS_REJECTED)
            ->and($after?->error_code)->toBe('E-100')
            ->and($after?->error_message)->toBe('شناسهٔ اقتصادی خریدار نامعتبر است.');
    });
});

it('throws on transport failure, so the queue can back off', function (): void {
    // The third outcome, and the only one worth retrying: the request never arrived.
    ($this->enableModule)();

    /** @var FakeMoadianDriver $driver */
    $driver = app(MoadianDriver::class);
    $driver->failNext();

    $invoice = ($this->makeInvoice)($this->party->getKey());

    inTenantContext($this->tenant, function () use ($invoice, $driver): void {
        $submission = app(SubmitInvoice::class)->enqueue($invoice);

        // Never null here: the module is enabled by `enableModule()` above.
        assert($submission instanceof MoadianInvoice);

        expect(fn () => app(SubmitInvoice::class)->send($submission))
            ->toThrow(RuntimeException::class);

        // Recorded rather than left stuck on `sending`, so a worker dying here does not
        // strand the row in a state no screen explains.
        expect($submission->fresh()?->status)->toBe(MoadianInvoice::STATUS_FAILED);

        // And nothing reached the authority — a failed transport is not a submission.
        expect($driver->sent())->toBeEmpty();
    });
});

it('hears about a document rejected after it was accepted', function (): void {
    // Why polling is a different question from sending: collapsing the two would make
    // "accepted" mean two things a day apart.
    ($this->enableModule)();

    $invoice = ($this->makeInvoice)($this->party->getKey());

    inTenantContext($this->tenant, function () use ($invoice): void {
        $submitter = app(SubmitInvoice::class);
        $enqueued = $submitter->enqueue($invoice);
        assert($enqueued instanceof MoadianInvoice);

        $submission = $submitter->send($enqueued);

        expect($submission->fresh()?->status)->toBe(MoadianInvoice::STATUS_ACCEPTED);

        /** @var FakeMoadianDriver $driver */
        $driver = app(MoadianDriver::class);
        $driver->markRejectedOnPoll((string) $submission->reference_number, 'E-200', 'سند پس از بررسی رد شد.');

        $refetched = $submission->fresh();
        assert($refetched instanceof MoadianInvoice);

        $submitter->poll($refetched);

        expect($submission->fresh()?->status)->toBe(MoadianInvoice::STATUS_REJECTED)
            ->and($submission->fresh()?->error_code)->toBe('E-200');
    });
});

/* ------------------------------------------------------------------ idempotency -- */

it('never creates a second submission for the same invoice', function (): void {
    /*
    | The spec's acceptance line, and a database-level claim rather than a code-level one:
    | two workers both reading "not yet submitted" is exactly the race a queue makes likely,
    | so a partial unique index is the guarantee.
    */
    ($this->enableModule)();

    $invoice = ($this->makeInvoice)($this->party->getKey());

    inTenantContext($this->tenant, function () use ($invoice): void {
        $submitter = app(SubmitInvoice::class);

        $first = $submitter->enqueue($invoice);
        $second = $submitter->enqueue($invoice);

        assert($first instanceof MoadianInvoice && $second instanceof MoadianInvoice);

        expect(idOf($first))->toBe(idOf($second));
        expect(MoadianInvoice::query()->where('type', MoadianInvoice::TYPE_MAIN)->count())->toBe(1);
    });
});

it('does not file an accepted document twice when resend is pressed', function (): void {
    ($this->enableModule)();

    $invoice = ($this->makeInvoice)($this->party->getKey());

    /** @var int $submissionId */
    $submissionId = inTenantContext($this->tenant, function () use ($invoice): int {
        $submitter = app(SubmitInvoice::class);

        $enqueued = $submitter->enqueue($invoice);
        assert($enqueued instanceof MoadianInvoice);

        return idOf($submitter->send($enqueued));
    });

    /** @var FakeMoadianDriver $driver */
    $driver = app(MoadianDriver::class);

    expect($driver->sent())->toHaveCount(1);

    $this->actingAs($this->owner)
        ->post($this->url.'/moadian/'.$submissionId.'/resend')
        ->assertSessionHasNoErrors();

    // Still one. Re-filing an accepted document is worse than a button that says so.
    expect($driver->sent())->toHaveCount(1);
});

it('rebuilds the payload on resend, because the shop fixed what was rejected', function (): void {
    ($this->enableModule)();

    /** @var FakeMoadianDriver $driver */
    $driver = app(MoadianDriver::class);
    $driver->rejectNext('E-100', 'شناسهٔ اقتصادی خریدار نامعتبر است.');

    $invoice = ($this->makeInvoice)($this->party->getKey());

    /** @var int $submissionId */
    $submissionId = inTenantContext($this->tenant, function () use ($invoice): int {
        $submitter = app(SubmitInvoice::class);

        $enqueued = $submitter->enqueue($invoice);
        assert($enqueued instanceof MoadianInvoice);

        return idOf($submitter->send($enqueued));
    });

    // The shop corrects the buyer in response to the rejection.
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $this->party->forceFill(['economic_code' => '499999999999'])->save();
    });

    Queue::fake();

    $this->actingAs($this->owner)
        ->post($this->url.'/moadian/'.$submissionId.'/resend')
        ->assertSessionHasNoErrors();

    inTenantContext($this->tenant, function () use ($submissionId): void {
        $submission = MoadianInvoice::query()->findOrFail($submissionId);

        expect($submission->status)->toBe(MoadianInvoice::STATUS_PENDING)
            ->and($submission->error_code)->toBeNull();

        // Resending the document that was already refused would be refused again.
        /** @var array<string, mixed> $buyer */
        $buyer = $submission->payload['buyer'];

        expect($buyer['economic_code'])->toBe('499999999999');
    });

    Queue::assertPushed(SubmitInvoiceJob::class);
});

/* --------------------------------------------------------------- void → cancel -- */

it('files a cancellation when an accepted invoice is voided', function (): void {
    ($this->enableModule)();

    $invoice = ($this->makeInvoice)($this->party->getKey());

    inTenantContext($this->tenant, function () use ($invoice): void {
        $finalised = app(FinaliseInvoice::class)->finalise($invoice->refresh(), idOf($this->owner));

        $submitter = app(SubmitInvoice::class);
        $submission = MoadianInvoice::query()->where('sales_invoice_id', idOf($finalised))->firstOrFail();

        $submitter->send($submission);

        expect($submission->fresh()?->status)->toBe(MoadianInvoice::STATUS_ACCEPTED);

        app(VoidInvoice::class)->void($finalised->refresh(), 'اشتباه صندوق‌دار', idOf($this->owner));

        expect(
            MoadianInvoice::query()
                ->where('sales_invoice_id', idOf($finalised))
                ->where('type', MoadianInvoice::TYPE_CANCEL)
                ->exists()
        )->toBeTrue();
    });
});

it('files no cancellation for an invoice the authority never accepted', function (): void {
    // Cancelling a document nobody received would be filing a correction to nothing.
    ($this->enableModule)();

    $invoice = ($this->makeInvoice)($this->party->getKey());

    // The queue is `sync` in tests, so without this the submission job runs the instant
    // finalisation dispatches it and the document IS accepted — which would make this test
    // assert the opposite of what it claims.
    Queue::fake();

    inTenantContext($this->tenant, function () use ($invoice): void {
        $finalised = app(FinaliseInvoice::class)->finalise($invoice->refresh(), idOf($this->owner));

        // Enqueued but never sent — still pending, so there is nothing out there to withdraw.
        app(VoidInvoice::class)->void($finalised->refresh(), 'اشتباه', idOf($this->owner));

        expect(
            MoadianInvoice::query()->where('type', MoadianInvoice::TYPE_CANCEL)->exists()
        )->toBeFalse();
    });
});

/* -------------------------------------------------------------------- the key -- */

it('never exposes the private key in the settings model’s array form', function (): void {
    // The spec makes this an acceptance criterion. `$hidden` is what covers `toArray()`,
    // which is what an Inertia prop, an API resource and a tenant export all call.
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $settings = MoadianSetting::query()->create([
            'provider' => 'fake',
            'private_key' => 'SECRET-KEY-MATERIAL',
            'is_enabled' => false,
        ]);

        expect($settings->toArray())->not->toHaveKey('private_key');
        expect(json_encode($settings))->not->toContain('SECRET-KEY-MATERIAL');

        // And encrypted at rest, so a dump or a replica does not carry it either.
        $raw = Illuminate\Support\Facades\DB::table('moadian_settings')
            ->where('id', idOf($settings))
            ->value('private_key');

        expect($raw)->not->toBe('SECRET-KEY-MATERIAL');
    });
});

/* ------------------------------------------------------------------ isolation -- */

it('never shows another shop’s submissions or lets one be resent', function (): void {
    ($this->enableModule)();

    $invoice = ($this->makeInvoice)($this->party->getKey());

    /** @var int $mineId */
    $mineId = inTenantContext($this->tenant, function () use ($invoice): int {
        $submission = app(SubmitInvoice::class)->enqueue($invoice);
        assert($submission instanceof MoadianInvoice);

        return idOf($submission);
    });

    /*
    | The positive half FIRST, and the order is load-bearing rather than stylistic.
    |
    | `seedRoles()` and `runFor()` move spatie's permission *team* id, so asserting on this
    | shop after building the neighbour resolves the owner's role against the wrong team and
    | 403s — a harness effect that reads exactly like a permission bug (docs/testing.md:
    | measure the harness before theorising about the domain).
    */
    $this->actingAs($this->owner)
        ->get($this->url.'/moadian')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('submissions.total', 1)->etc());

    $other = Tenant::factory()->withDomain()->create();

    /*
    | The neighbour buys the add-on too, and that is the point.
    |
    | Without it they are refused by `module:moadian` and the test proves only that the plan
    | gate works — a green isolation test that never exercises RLS at all. The claim worth
    | making is that a shop which legitimately HAS the module still cannot see another
    | shop's filings.
    */
    ($this->buyMoadian)($other, subscribe($other, 'pro'));

    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($other);

    $neighbour = app(TenantContext::class)->runFor($other, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Owner');

        return $user;
    });

    $this->actingAs($neighbour)
        ->get(appUrl().'/moadian')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('submissions.total', 0)->etc());

    // 404, not 403: a 403 confirms the row exists.
    $this->actingAs($neighbour)
        ->post(appUrl().'/moadian/'.$mineId.'/resend')
        ->assertNotFound();
})->group('isolation');
