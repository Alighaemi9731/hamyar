<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;

/*
| Found in production, by the first person to register a shop and then come back to it.
|
| Registration redirects to `/login` on the new subdomain, so the first visit worked and
| nothing looked wrong. Every visit after that begins at the shop's own address — and
| that had no route, so it served the framework's bare English "Not Found" to a Persian
| shopkeeper trying to reach their till.
|
| The lesson these tests exist to hold: a route nobody wrote is not covered by a test
| nobody wrote either, and "can a shopkeeper do this?" has to be asked about the paths
| people arrive on, not only the ones the happy path hands them.
*/

it('sends a signed-out visitor from the shop root towards the login page', function (): void {
    $tenant = Tenant::factory()->withDomain()->create();

    $this->get(tenantUrl($tenant).'/')
        ->assertRedirect(tenantUrl($tenant).'/dashboard');
});

it('sends a signed-in user from the shop root to their dashboard', function (): void {
    $tenant = Tenant::factory()->withDomain()->create();

    $user = app(TenantContext::class)->runFor(
        $tenant,
        fn () => User::factory()->create(),
    );

    $this->actingAs($user)
        ->get(tenantUrl($tenant).'/')
        ->assertRedirect(tenantUrl($tenant).'/dashboard');
});

it('still refuses the root of a hostname belonging to no shop', function (): void {
    $this->get(unknownTenantUrl('/'))->assertNotFound();
})->group('isolation');

it('leaves the central site alone', function (): void {
    $this->get('http://'.config()->string('app.domain').'/')
        ->assertOk();
});
