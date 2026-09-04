<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;

/**
 * The till opened from a link that carries a code — the passport's «فروش» button.
 *
 * Only the string is echoed back to the page. The scan box then looks it up through the
 * same scoped endpoint a reader's scan uses, which is why this test asserts the echo and
 * nothing about the device: there is no lookup here to isolate.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    $this->owner = app(TenantContext::class)->runFor($this->tenant, function (): User {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        // The till refuses to open (409) for a shop with no sellable warehouse to stand
        // in; the handover is about the code, so the shop gets its one counter here.
        Warehouse::factory()->create(['is_sellable' => true, 'is_default' => true]);

        return $owner;
    });
});

afterEach(fn () => app(TenantContext::class)->forget());

it('hands a code from the link to the scan box, and null when there is none', function (): void {
    $this->actingAs($this->owner)
        ->get($this->url.'/sales/pos?imei=356938035643809')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Sales::Pos/Index')
            ->where('scan', '356938035643809')
        );

    $this->actingAs($this->owner)
        ->get($this->url.'/sales/pos')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('scan', null));
});

it('drops a code that is blank or absurdly long rather than typing it into the box', function (): void {
    $this->actingAs($this->owner)
        ->get($this->url.'/sales/pos?imei=%20%20')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('scan', null));

    $this->actingAs($this->owner)
        ->get($this->url.'/sales/pos?imei='.str_repeat('9', 65))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('scan', null));
});
