<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;

/*
| Found in production, by the first person to register a shop and then come back to it.
|
| Registration handed them a login page, so the first visit worked and nothing looked
| wrong. Every visit after that began at the address they had kept — the bare root — and
| that had no route, so it served the framework's English "Not Found" to a Persian
| shopkeeper trying to reach their till.
|
| The address has since changed under these tests: ADR 0017 retired per-shop hostnames,
| so the root a shopkeeper types is `app.<apex>/`, one address for every shop, and which
| shop it opens comes from the session rather than from the URL. The bug it guards is the
| same one, and so is the lesson: a route nobody wrote is not covered by a test nobody
| wrote either, and "can a shopkeeper do this?" has to be asked about the paths people
| actually arrive on, not only the ones the happy path hands them.
*/

it('sends a signed-out visitor from the application root towards the login page', function (): void {
    // Two hops. `/` is a plain redirect to `/dashboard` — that is the route whose absence
    // caused the production 404 — and ResolveTenant carries a session with no tenant on
    // to `/login` from there. This asserts the first hop; the isolation test below owns
    // the second.
    $this->get(appUrl().'/')
        ->assertRedirect(appUrl().'/dashboard');
});

it('sends a signed-in user from the application root to their dashboard', function (): void {
    $tenant = Tenant::factory()->withDomain()->create();

    $user = app(TenantContext::class)->runFor(
        $tenant,
        fn () => User::factory()->create(),
    );

    $this->actingAs($user)
        ->get(appUrl().'/')
        ->assertRedirect(appUrl().'/dashboard');
});

it('sends a signed-out visitor at the dashboard to the login page', function (): void {
    /*
    | What replaced "the root of a hostname belonging to no shop still 404s".
    |
    | There is no per-shop hostname left to be unknown (ADR 0017), so the fail-closed
    | guarantee moved into the session: a request carrying no `tenant_id` resolves no
    | shop, and therefore — RLS denying every row while `app.tenant_id` is unset — can
    | read no shop's data. It lands on the login page instead of on somebody's till.
    |
    | Still in the isolation group, because that is the guarantee being asserted: no
    | context means no rows, never a fallback to a default tenant.
    */
    $this->get(appUrl('/dashboard'))
        ->assertRedirect(appUrl('/login'));
})->group('isolation');

it('leaves the central site alone', function (): void {
    $this->get(centralUrl('/'))
        ->assertOk();
});
