<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Reporting\Services\ShopSetupProgress;
use App\Support\Tenancy\TenantContext;

/**
 * The first morning's checklist on the dashboard.
 *
 * Steps are facts read from the tables, so the assertions create the fact and read the
 * step back rather than flipping a flag. The dismissal is a tenant setting: it has to
 * hold across users, not across one browser.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    [$this->owner, $this->seller] = app(TenantContext::class)->runFor($this->tenant, function (): array {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        $seller = User::factory()->create();
        $seller->assignRole('Salesperson');

        return [$owner, $seller];
    });
});

afterEach(fn () => app(TenantContext::class)->forget());

it('walks a new shop through six steps, in the order its own work happens', function (): void {
    $this->actingAs($this->owner)
        ->get($this->url.'/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Reporting::Dashboard/Index')
            ->where('setup.total', 6)
            ->has('setup.steps', 6)
            ->where('setup.steps.0.key', 'product')
            ->where('setup.steps.0.done', false)
            ->where('setup.steps.0.href', '/catalog/products/create')
            ->where('setup.steps.3.key', 'sale')
            // Two people already have accounts here, so the staff step is done.
            ->where('setup.steps.5.key', 'staff')
            ->where('setup.steps.5.done', true)
            ->where('setup.done', 1)
        );
});

it('reads a step from the tables, not from a flag', function (): void {
    inTenantContext($this->tenant, fn () => Product::factory()->create());

    $this->actingAs($this->owner)
        ->get($this->url.'/dashboard')
        ->assertInertia(fn ($page) => $page
            ->where('setup.steps.0.done', true)
            ->where('setup.done', 2)
        );
});

it('is not a salesperson\'s business', function (): void {
    $this->actingAs($this->seller)
        ->get($this->url.'/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('setup', null));
});

it('stays dismissed for the whole shop once the owner says later', function (): void {
    $this->actingAs($this->owner)
        ->from($this->url.'/dashboard')
        ->post($this->url.'/dashboard/setup/dismiss')
        ->assertRedirect($this->url.'/dashboard');

    expect($this->tenant->fresh()?->setting(ShopSetupProgress::SETTING))->not->toBeNull();

    $this->actingAs($this->owner)
        ->get($this->url.'/dashboard')
        ->assertInertia(fn ($page) => $page->where('setup', null));
});

it('refuses the dismissal from somebody the card was never shown to', function (): void {
    $this->actingAs($this->seller)
        ->post($this->url.'/dashboard/setup/dismiss')
        ->assertForbidden();

    expect($this->tenant->fresh()?->setting(ShopSetupProgress::SETTING))->toBeNull();
});
