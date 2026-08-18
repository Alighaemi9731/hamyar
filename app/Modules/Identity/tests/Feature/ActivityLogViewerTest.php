<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\PriceLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\PriceResolver;
use App\Modules\CRM\Models\Party;
use App\Modules\Identity\Models\Activity;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Repairs\Models\RepairTicket;
use App\Support\Audit\Redactor;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;

/**
 * The audit-log viewer, and the question it exists to answer.
 *
 * «کی این قیمت را عوض کرد؟» is a weekly support call at fifty evaluators, and until
 * 11c the honest answer was that nothing in the product knew. These tests are mostly
 * about that: that the entry gets written, that it carries the before, the after and
 * the person, and that an owner can reach it from the product rather than by finding
 * the log and filtering it down by hand.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = tenantUrl($this->tenant);

    app(TenantProvisioner::class)->seedRoles($this->tenant);

    [$this->owner, $this->seller] = app(TenantContext::class)->runFor($this->tenant, function (): array {
        $owner = User::factory()->create(['name' => 'مالک']);
        $owner->assignRole('Owner');

        $seller = User::factory()->create(['name' => 'فروشنده']);
        $seller->assignRole('Salesperson');

        return [$owner, $seller];
    });

    // The shared helper rather than TenantContext::runFor() directly: same call, and
    // it is the one every other suite uses.
    $this->inTenant = fn (Closure $callback): mixed => inTenantContext($this->tenant, $callback);
});

/* ------------------------------------------------------------------ coverage -- */

it('records who changed a price, with the amount before and after', function (): void {
    /** @var ProductVariant $variant */
    $variant = ($this->inTenant)(function (): ProductVariant {
        $product = Product::factory()->create(['name' => 'آیفون ۱۵ پرو']);

        return ProductVariant::factory()->create(['product_id' => $product->id]);
    });

    $level = ($this->inTenant)(fn (): PriceLevel => PriceLevel::factory()->create());

    $this->actingAs($this->owner);

    ($this->inTenant)(function () use ($variant, $level): void {
        $prices = app(PriceResolver::class);
        $prices->setPrice($variant->id, $level->id, 18_900_000);
        $prices->setPrice($variant->id, $level->id, 19_500_000);
    });

    /** @var Activity $entry */
    $entry = ($this->inTenant)(fn (): Activity => Activity::query()
        ->where('event', 'price_changed')
        ->latest()
        ->firstOrFail());

    // The whole point in one assertion: what it was, what it is, and who.
    expect($entry->getProperty('old.price'))->toBe(18_900_000)
        ->and($entry->getProperty('attributes.price'))->toBe(19_500_000)
        ->and($entry->causer_id)->toBe($this->owner->id)
        // Subject is the VARIANT, not the price row: a price row exists for one moment
        // and never changes, so its own history would hold a single entry. The thing a
        // shopkeeper opens a history for is the phone.
        ->and($entry->subject_type)->toBe(ProductVariant::class)
        ->and($entry->subject_id)->toBe($variant->id);
});

it('does not record the opening price as a change', function (): void {
    // Otherwise a first import writes one entry per variant per level saying nothing
    // happened yet, and buries the entries that matter under thousands that do not.
    /** @var ProductVariant $variant */
    $variant = ($this->inTenant)(function (): ProductVariant {
        $product = Product::factory()->create();

        return ProductVariant::factory()->create(['product_id' => $product->id]);
    });

    $level = ($this->inTenant)(fn (): PriceLevel => PriceLevel::factory()->create());

    $this->actingAs($this->owner);

    ($this->inTenant)(fn () => app(PriceResolver::class)->setPrice($variant->id, $level->id, 18_900_000));

    expect(($this->inTenant)(fn (): int => Activity::query()->where('event', 'price_changed')->count()))
        ->toBe(0);
});

it('records a product edit with its before and after', function (): void {
    $this->actingAs($this->owner);

    /** @var Product $product */
    $product = ($this->inTenant)(function (): Product {
        $product = Product::factory()->create(['name' => 'گلکسی S24']);
        $product->update(['name' => 'گلکسی S25']);

        return $product;
    });

    /** @var Activity $entry */
    $entry = ($this->inTenant)(fn (): Activity => Activity::query()
        ->where('subject_type', Product::class)
        ->where('subject_id', $product->id)
        ->where('event', 'updated')
        ->firstOrFail());

    // spatie v5 writes the model diff to `attribute_changes`, NOT to `properties`.
    // Reading the wrong column is why this screen rendered an empty change list for
    // every model in the product until 11c, so the test names the column.
    /** @var Collection<string, mixed> $payload */
    $payload = $entry->getAttribute('attribute_changes');

    /** @var array{old: array<string, mixed>, attributes: array<string, mixed>} $changes */
    $changes = $payload->toArray();

    expect($changes['old']['name'])->toBe('گلکسی S24')
        ->and($changes['attributes']['name'])->toBe('گلکسی S25')
        // Persian, because `description` is also the column the free-text filter
        // searches — an owner typing «ویرایش» into a Persian screen matched nothing
        // while spatie's default English event name was stored.
        ->and($entry->description)->toBe('ویرایش شد');
});

it('never audits the tenant id', function (): void {
    // It is on `$fillable` only so the tenancy trait can fill it, never changes, and
    // is not a fact anybody audits — but it would appear on every `created` entry.
    expect((new Product)->getActivitylogOptions()->logAttributes)->not->toContain('tenant_id');
});

/* -------------------------------------------------------------------- secrets -- */

it('masks a secret that reaches the log through custom properties', function (): void {
    $this->actingAs($this->owner);

    ($this->inTenant)(function (): void {
        $ticket = new RepairTicket;
        $ticket->id = 4517;

        activity('repairs')
            ->performedOn($ticket)
            ->withProperties([
                'device_passcode' => '4517',
                'attributes' => ['device_passcode' => '4517', 'status' => 'ready'],
                'ip' => '10.0.0.1',
            ])
            ->log('تست');
    });

    /** @var Activity $entry */
    $entry = ($this->inTenant)(fn (): Activity => Activity::query()->where('log_name', 'repairs')->firstOrFail());

    expect($entry->getProperty('device_passcode'))->toBe(Redactor::MASK)
        // Nested too: `attributes` and `old` are one level down, and a guard that only
        // walked the top level would mask the copy nobody reads and print the one the
        // viewer renders.
        ->and($entry->getProperty('attributes.device_passcode'))->toBe(Redactor::MASK)
        // And nothing else — over-masking an audit log makes it useless in a different
        // way from under-masking it.
        ->and($entry->getProperty('ip'))->toBe('10.0.0.1')
        ->and($entry->getProperty('attributes.status'))->toBe('ready');
});

it('masks in the database, not only on the screen', function (): void {
    // A guard in the controller would leave the clear value sitting in the table for a
    // database console, a backup, or the next screen written over this data.
    $this->actingAs($this->owner);

    ($this->inTenant)(function (): void {
        $ticket = new RepairTicket;
        $ticket->id = 4517;

        activity('repairs')->performedOn($ticket)->withProperties(['device_passcode' => '4517'])->log('تست');
    });

    /** @var string $raw */
    $raw = ($this->inTenant)(function (): string {
        $stored = Activity::query()->where('log_name', 'repairs')->firstOrFail()->getRawOriginal('properties');

        return is_string($stored) ? $stored : '';
    });

    expect($raw)->not->toContain('4517');
});

it('derives the secret list from the model rather than a list of its own', function (): void {
    $secrets = app(Redactor::class)->secretsFor(RepairTicket::class);

    // `$hidden` plus every encrypted cast. The point is that a field added to either
    // one is masked the day it is added, by the declaration that already protects it
    // everywhere else — not on the day somebody remembers the audit log exists.
    expect($secrets)->toContain('device_passcode')
        ->and($secrets)->toContain('tracking_token')
        ->and($secrets)->toContain('approval_token');
});

/* -------------------------------------------------------------------- filters -- */

it('filters to one record, and says whose history it is showing', function (): void {
    $this->actingAs($this->owner);

    /** @var array{Product, Product} $products */
    $products = ($this->inTenant)(function (): array {
        $watched = Product::factory()->create(['name' => 'آیفون ۱۵ پرو']);
        $other = Product::factory()->create(['name' => 'گلکسی S25']);

        $watched->update(['sku' => 'IP15P']);
        $other->update(['sku' => 'SGS25']);

        return [$watched, $other];
    });

    [$watched, $other] = $products;

    $this->get($this->url."/settings/activity?subject=product&record={$watched->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // The heading names the record. Without it the page reads as the global log
            // that happens to be showing two entries.
            ->where('record.name', 'آیفون ۱۵ پرو')
            ->where('record.label', 'کالا')
            // Every row is about this product or something belonging to it — never
            // the other product, which has its own edits in the same table.
            ->where('activities.data', fn (Collection $rows): bool => $rows->isNotEmpty()
                && $rows->every(fn (array $row): bool => $row['subject'] === 'product'
                    ? $row['subject_id'] === $watched->id
                    : $row['subject'] === 'variant'))
        );

    expect($other->id)->not->toBe($watched->id);
});

it("shows a price change on the product's history, not only the variant's", function (): void {
    // Found on the browser walk, with every filter test above passing. A price is
    // logged against the variant because that is what it belongs to; a shopkeeper
    // opens the history of the PRODUCT, because that is the screen they were on when
    // they asked. The «تاریخچه» link built to answer «کی این قیمت را عوض کرد؟» led to
    // a page containing every kind of change except that one.
    //
    // Not an edge case: ADR 0013 makes one product with one no-axis variant the shape
    // of every imported row, so for nearly every product the two are the same object.
    $this->actingAs($this->owner);

    /** @var array{Product, ProductVariant} $catalogue */
    $catalogue = ($this->inTenant)(function (): array {
        $product = Product::factory()->create(['name' => 'آیفون ۱۵ پرو']);

        return [$product, ProductVariant::factory()->create(['product_id' => $product->id])];
    });

    [$product, $variant] = $catalogue;

    $level = ($this->inTenant)(fn (): PriceLevel => PriceLevel::factory()->create());

    ($this->inTenant)(function () use ($variant, $level): void {
        $prices = app(PriceResolver::class);
        $prices->setPrice($variant->id, $level->id, 18_900_000);
        $prices->setPrice($variant->id, $level->id, 19_500_000);
    });

    $this->get($this->url."/settings/activity?subject=product&record={$product->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where(
            'activities.data',
            fn (Collection $rows): bool => $rows->contains(
                fn (array $row): bool => $row['event'] === 'price_changed'
            )
        ));
});

it("keeps another product's variants out of this product's history", function (): void {
    // The expansion widens the query, and a widening bug is invisible on a screen that
    // is supposed to show more than the record's own rows.
    $this->actingAs($this->owner);

    /** @var array{Product, ProductVariant} $mine */
    $mine = ($this->inTenant)(function (): array {
        $product = Product::factory()->create();

        return [$product, ProductVariant::factory()->create(['product_id' => $product->id])];
    });

    /** @var ProductVariant $theirs */
    $theirs = ($this->inTenant)(function (): ProductVariant {
        $other = Product::factory()->create();

        return ProductVariant::factory()->create(['product_id' => $other->id]);
    });

    ($this->inTenant)(fn () => $theirs->update(['barcode' => '6221001']));

    [$product] = $mine;

    $this->get($this->url."/settings/activity?subject=product&record={$product->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where(
            'activities.data',
            fn (Collection $rows): bool => $rows->doesntContain(
                fn (array $row): bool => $row['subject_id'] === $theirs->id
                    && $row['subject'] === 'variant'
            )
        ));
});

it('matches nothing when asked for a kind of record that does not exist', function (): void {
    // Ignoring an unknown key would show the whole log while the screen claims to be
    // filtered, which is the shape of every audit-log bug worth having.
    $this->actingAs($this->owner);

    ($this->inTenant)(fn () => Product::factory()->create()->update(['sku' => 'X']));

    $this->get($this->url.'/settings/activity?subject=nonsense')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('activities.total', 0));
});

it('rejects a record id with no subject beside it', function (): void {
    // `subject_id` is only unique within a type, so a bare record id would match a
    // product, a party and a user at once. The error belongs to `subject` — that is
    // the field that is missing.
    $this->actingAs($this->owner);

    $this->get($this->url.'/settings/activity?record=12')
        ->assertRedirect($this->url.'/settings/activity')
        ->assertSessionHasErrors('subject');
});

it('sends a bad filter to the clean log rather than back to itself', function (): void {
    // `back()` is the FormRequest default and loops here: the only way to arrive with
    // a malformed filter is a hand-edited URL, and `back()` from that URL is that URL.
    $this->actingAs($this->owner);

    $this->from($this->url.'/settings/activity?from=yesterday')
        ->get($this->url.'/settings/activity?from=yesterday')
        ->assertRedirect($this->url.'/settings/activity');
});

it('filters by actor', function (): void {
    ($this->inTenant)(function (): void {
        $this->actingAs($this->owner);
        Product::factory()->create(['name' => 'کالای مالک']);
    });

    $this->actingAs($this->seller);

    ($this->inTenant)(fn () => Product::factory()->create(['name' => 'کالای فروشنده']));

    $this->actingAs($this->owner)
        ->get($this->url."/settings/activity?actor={$this->seller->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where(
            'activities.data',
            fn (Collection $rows): bool => $rows->every(fn (array $row): bool => $row['causer'] === 'فروشنده')
        ));
});

it('lists only staff who actually appear in the log', function (): void {
    // A dropdown of every user who ever existed makes the reader hunt through names
    // that cannot match a row.
    $this->actingAs($this->owner);

    ($this->inTenant)(fn () => Product::factory()->create());

    $this->get($this->url.'/settings/activity')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where(
            'actors',
            fn (Collection $actors): bool => $actors->pluck('name')->doesntContain('فروشنده')
        ));
});

it('searches the Persian description', function (): void {
    $this->actingAs($this->owner);

    ($this->inTenant)(fn () => Product::factory()->create()->update(['sku' => 'ABC']));

    $this->get($this->url.'/settings/activity?q=ویرایش')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where(
            'activities.data',
            fn (Collection $rows): bool => $rows->isNotEmpty()
        ));
});

it('treats a wildcard in the search term as a literal', function (): void {
    // `%` unescaped would match every row and read as "search is broken but returns
    // everything", which is worse than returning nothing.
    $this->actingAs($this->owner);

    ($this->inTenant)(fn () => Product::factory()->create());

    $this->get($this->url.'/settings/activity?q=%')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('activities.total', 0));
});

it('accepts a Jalali date range typed in Persian digits', function (): void {
    $this->actingAs($this->owner);

    ($this->inTenant)(fn () => Product::factory()->create());

    // A Persian date picker on a Persian keyboard sends «۱۴۰۵/۰۱/۰۱». Rejecting it
    // would blame the shopkeeper for typing their own numerals.
    $this->get($this->url.'/settings/activity?from=۱۴۰۵/۰۱/۰۱')
        ->assertOk()
        ->assertSessionHasNoErrors();
});

it('rejects a malformed date rather than failing inside the Jalali parser', function (): void {
    $this->actingAs($this->owner);

    $this->get($this->url.'/settings/activity?from=yesterday')->assertSessionHasErrors('from');
});

/* ------------------------------------------------------------------ isolation -- */

it('shows tenant B nothing of tenant A history', function (): void {
    $other = Tenant::factory()->withDomain()->create();
    app(TenantProvisioner::class)->seedRoles($other);

    /** @var User $intruder */
    $intruder = app(TenantContext::class)->runFor($other, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Owner');

        return $user;
    });

    $this->actingAs($this->owner);

    /** @var Product $product */
    $product = ($this->inTenant)(function (): Product {
        $product = Product::factory()->create(['name' => 'محرمانه']);
        $product->update(['sku' => 'SECRET']);

        return $product;
    });

    // Asking for tenant A's product by id, from tenant B's hostname, as tenant B's
    // Owner. RLS answers, not the controller.
    $this->actingAs($intruder)
        ->get(tenantUrl($other)."/settings/activity?subject=product&record={$product->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('activities.total', 0)
            // And the heading must not leak the name either: `nameFor` queries under
            // RLS, so there is nothing to find and the id stands in for it.
            ->where('record.name', '#'.$product->id)
        );
});

it('refuses the audit log to a Salesperson', function (): void {
    $this->actingAs($this->seller)->get($this->url.'/settings/activity')->assertForbidden();
});
