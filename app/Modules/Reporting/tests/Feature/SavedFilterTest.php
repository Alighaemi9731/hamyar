<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Reporting\Models\SavedFilter;
use App\Support\Tenancy\TenantContext;

/**
 * Saved filter presets — per user, per report screen.
 *
 * ## What a preset is, and what it deliberately is not
 *
 * It is a bookmark: a report key and a bag of filters. It carries **no permission**, which
 * is what lets it be stored loosely — the screen it opens gates itself exactly as it does
 * for a typed URL. The test for that is below, and it is the one that matters: a preset
 * pointing at a report the viewer may not open must still 403.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = tenantUrl($this->tenant);

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    /** @var array{User, User} $fixtures */
    $fixtures = app(TenantContext::class)->runFor($this->tenant, function (): array {
        $owner = User::factory()->create(['name' => 'مالک']);
        $owner->assignRole('Owner');

        $colleague = User::factory()->create(['name' => 'همکار']);
        $colleague->assignRole('Accountant');

        return [$owner, $colleague];
    });

    [$this->owner, $this->colleague] = $fixtures;
});

afterEach(fn () => app(TenantContext::class)->forget());

it('saves a preset and hands it back to the screen it belongs to', function (): void {
    $this->actingAs($this->owner)
        ->post($this->url.'/reporting/presets', [
            'report_key' => 'financial',
            'name' => 'سه ماه گذشته',
            'filters' => ['cut' => 'aging', 'direction' => 'receivable'],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $this->actingAs($this->owner)
        ->get($this->url.'/reporting/financial')
        ->assertOk()
        ->assertInertia(function ($page): void {
            $presets = rowsOf($page, 'presets');

            expect($presets)->toHaveCount(1);
            expect($presets[0]['name'])->toBe('سه ماه گذشته')
                ->and($presets[0]['filters'])->toBe(['cut' => 'aging', 'direction' => 'receivable']);
        });

    // And not onto another screen: presets are keyed by report.
    $this->actingAs($this->owner)
        ->get($this->url.'/reporting/tax')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('presets', [])->etc());
});

it('updates rather than duplicating when the same name is saved twice', function (): void {
    foreach (['receivable', 'payable'] as $direction) {
        $this->actingAs($this->owner)
            ->post($this->url.'/reporting/presets', [
                'report_key' => 'financial',
                'name' => 'سه ماه گذشته',
                'filters' => ['cut' => 'aging', 'direction' => $direction],
            ])
            ->assertSessionHasNoErrors();
    }

    /*
    | A shopkeeper who saves the same name after adjusting the range means *update that*.
    | The alternative — a validation error saying the name is taken — asks them to delete
    | a preset in order to change it.
    */
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $presets = SavedFilter::query()->where('report_key', 'financial')->get();

        expect($presets)->toHaveCount(1);
        expect($presets->first()?->filters['direction'] ?? null)->toBe('payable');
    });
});

it('accepts a preset of the defaults, with no filter keys at all', function (): void {
    /*
    | The rule from CLAUDE.md, in its non-multipart form: an optional array key that is
    | simply absent must be accepted. A report sitting entirely on its defaults sends no
    | filters, and "the defaults" is a legitimate thing to save — `present` or `required`
    | on `filters` would reject exactly the ordinary case, and only a test that omits the
    | key entirely catches it, because building the payload in PHP always includes it.
    */
    $this->actingAs($this->owner)
        ->post($this->url.'/reporting/presets', [
            'report_key' => 'sales',
            'name' => 'پیش‌فرض',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    app(TenantContext::class)->runFor($this->tenant, function (): void {
        expect(SavedFilter::query()->where('name', 'پیش‌فرض')->firstOrFail()->filters)->toBe([]);
    });
});

it('refuses a report key that is not a screen', function (): void {
    $this->actingAs($this->owner)
        ->post($this->url.'/reporting/presets', [
            'report_key' => 'sales.daily',
            'name' => 'دیروز',
            'filters' => ['cut' => 'daily'],
        ])
        ->assertSessionHasErrors('report_key');

    /*
    | `sales.daily` is a catalogue ROW, not a screen. A preset saved against one would
    | never be handed back to any page, and the shopkeeper's conclusion would be that
    | saving presets does not work — so it is rejected loudly instead.
    */
    $this->actingAs($this->owner)
        ->post($this->url.'/reporting/presets', [
            'report_key' => 'financial',
            'name' => '',
            'filters' => [],
        ])
        ->assertSessionHasErrors('name');
});

it('refuses a filter payload that is not a flat map of short strings', function (): void {
    $this->actingAs($this->owner)
        ->post($this->url.'/reporting/presets', [
            'report_key' => 'financial',
            'name' => 'خرابکاری',
            'filters' => ['cut' => ['nested', 'array']],
        ])
        ->assertSessionHasErrors('filters.cut');
});

it('deletes a preset and refuses to delete a colleague’s', function (): void {
    $this->actingAs($this->owner)
        ->post($this->url.'/reporting/presets', [
            'report_key' => 'financial',
            'name' => 'مال من',
            'filters' => ['cut' => 'aging'],
        ])->assertSessionHasNoErrors();

    /** @var SavedFilter $preset */
    $preset = app(TenantContext::class)->runFor(
        $this->tenant,
        fn (): SavedFilter => SavedFilter::query()->where('name', 'مال من')->firstOrFail(),
    );

    /*
    | Personal, not shop-wide (see the migration): a colleague gets 404 rather than 403,
    | because from their side a preset they do not own is indistinguishable from one that
    | never existed.
    */
    $this->actingAs($this->colleague)
        ->delete($this->url.'/reporting/presets/'.idOf($preset))
        ->assertNotFound();

    $this->actingAs($this->owner)
        ->delete($this->url.'/reporting/presets/'.idOf($preset))
        ->assertRedirect();

    app(TenantContext::class)->runFor($this->tenant, function (): void {
        expect(SavedFilter::query()->count())->toBe(0);
    });
});

it('grants nothing: a preset for a report the viewer may not open still refuses', function (): void {
    /*
    | The claim that lets presets be stored as opaque JSON. A Cashier can save a preset for
    | the tax screen — nothing stops them typing the URL either — and opening it asks
    | `ReportAccess` exactly as a typed URL does.
    */
    $cashier = app(TenantContext::class)->runFor($this->tenant, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Cashier');

        return $user;
    });

    $this->actingAs($cashier)
        ->post($this->url.'/reporting/presets', [
            'report_key' => 'tax',
            'name' => 'اظهارنامه',
            'filters' => ['cut' => 'monthly'],
        ])->assertSessionHasNoErrors();

    $this->actingAs($cashier)
        ->get($this->url.'/reporting/tax?cut=monthly')
        ->assertForbidden();
});

it('keeps one shop’s presets out of another’s', function (): void {
    $this->actingAs($this->owner)
        ->post($this->url.'/reporting/presets', [
            'report_key' => 'financial',
            'name' => 'مال فروشگاه اول',
            'filters' => ['cut' => 'aging'],
        ])->assertSessionHasNoErrors();

    /** @var SavedFilter $preset */
    $preset = app(TenantContext::class)->runFor(
        $this->tenant,
        fn (): SavedFilter => SavedFilter::query()->where('name', 'مال فروشگاه اول')->firstOrFail(),
    );

    $other = Tenant::factory()->withDomain()->create();
    subscribe($other, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($other);

    $neighbour = app(TenantContext::class)->runFor($other, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Owner');

        return $user;
    });

    // The positive half: the preset exists and its owner sees it.
    $this->actingAs($this->owner)
        ->get($this->url.'/reporting/financial')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->count('presets', 1)->etc());

    $this->actingAs($neighbour)
        ->get(tenantUrl($other).'/reporting/financial')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('presets', [])->etc());

    // And it cannot be reached by id, which RLS decides rather than the controller.
    $this->actingAs($neighbour)
        ->delete(tenantUrl($other).'/reporting/presets/'.idOf($preset))
        ->assertNotFound();
})->group('isolation');
