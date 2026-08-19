<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\PlatformUser;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;

/**
 * The queue dashboard is a tenancy boundary.
 *
 * Horizon renders **job payloads**. `SendSmsJob` carries a customer's phone number and
 * the text of the message; `SubmitInvoiceJob` carries an invoice. Every shop's jobs
 * land in the same Redis and appear on the same screen, and none of it is a database
 * row — so RLS, which is the whole tenancy guarantee, cannot reach it.
 *
 * A shop owner who opened this page would read the other forty-nine shops' customers.
 * That makes it the widest single leak available in the product, and the one least
 * likely to be noticed, because it does not look like a leak. It looks like a queue
 * monitor that somebody remembered to install.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->staff = PlatformUser::factory()->create(['is_active' => true]);
    $this->horizon = centralUrl('/horizon');

    $this->tenant = Tenant::factory()->withDomain()->create();
    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    $this->owner = app(TenantContext::class)->runFor($this->tenant, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Owner');

        return $user;
    });
});

afterEach(fn () => app(TenantContext::class)->forget());

it('refuses a shop owner, the most privileged role a tenant has', function (): void {
    /*
    | Owner is the top of a shop's role ladder — every permission the product grants a
    | tenant. If anything on a tenant guard could reach this page it would be an Owner,
    | which is why the test uses one rather than a bare user.
    */
    $this->actingAs($this->owner)
        ->get($this->horizon)
        ->assertForbidden();
})->group('isolation');

it('does not answer at all on a shop’s own hostname', function (): void {
    // Defence in depth behind the gate: a dashboard that does not exist on
    // `<shop>.<apex>` is one fewer surface, and one fewer phishing target.
    $this->actingAs($this->staff, 'platform')
        ->get(tenantUrl($this->tenant).'/horizon')
        ->assertNotFound();
})->group('isolation');

it('refuses a guest', function (): void {
    $this->get($this->horizon)->assertForbidden();
});

it('lets active platform staff in', function (): void {
    $this->actingAs($this->staff, 'platform')->get($this->horizon)->assertOk();
});

it('locks out staff the moment their account is deactivated', function (): void {
    /*
    | Checked on the request rather than trusted from the session, so revoking access
    | takes effect now — which is the case that actually matters, somebody leaving
    | under a cloud with a live session in a browser.
    */
    $this->staff->update(['is_active' => false]);

    $this->actingAs($this->staff, 'platform')->get($this->horizon)->assertForbidden();
});
