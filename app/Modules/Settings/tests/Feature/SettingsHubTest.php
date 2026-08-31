<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Settings\Services\SettingsCatalogue;

/**
 * The settings hub, and the thing that made it necessary.
 *
 * `/settings` returned 404 for every user on every page for as long as the sidebar has
 * existed. The first test here is the one that would have caught it.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->create();
    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    /*
    | `->fresh()` is load-bearing, not tidiness.
    |
    | A model straight out of `create()` holds only the attributes that were inserted, so
    | nullable columns the factory leaves alone — `two_factor_confirmed_at` among them —
    | are *missing* rather than null. With `preventAccessingMissingAttributes` on outside
    | production, reading one throws, and `/settings/two-factor` answered 500 in this suite
    | while returning 200 in a browser. The session guard loads the row; so does this now,
    | which is the point of a fixture.
    |
    | `refresh()` rather than `fresh()`: same reload, but it returns `static` instead of
    | `static|null`, so the closure's return type holds at Larastan level 8.
    */
    $this->owner = inTenantContext($this->tenant, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Owner');

        $user->refresh();

        return $user;
    });
});

it('answers the nav item that used to 404', function (): void {
    $this->actingAs($this->owner)
        ->get(appUrl('/settings'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Settings::Settings/Index'));
});

/*
| The catalogue names routes as strings and permissions as strings, neither of which the
| type system checks. Both drift silently: a route renamed in another module leaves a card
| that 404s, which is precisely the defect this whole screen exists to fix — it would be a
| poor joke to replace one dead link with six.
*/
it('links only to routes that actually answer', function (): void {
    $this->actingAs($this->owner);

    foreach (SettingsCatalogue::destinations() as $destination) {
        $response = $this->get(appUrl($destination['href']));

        expect($response->status())->toBeIn(
            [200, 302],
            "[{$destination['key']}] {$destination['href']} answered {$response->status()}; the settings hub would link to a dead page.",
        );
    }
});

it('hides what a role may not open, and drops the group with it', function (): void {
    /** @var User $cashier */
    $cashier = inTenantContext($this->tenant, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Cashier');
        $user->refresh();

        return $user;
    });

    /** @var list<array{key: string, label: string, items: list<array{key: string, title: string, description: string, href: string}>}> $visible */
    $visible = inTenantContext($this->tenant, fn () => SettingsCatalogue::visibleTo($cashier));

    $keys = [];

    foreach ($visible as $group) {
        foreach ($group['items'] as $item) {
            $keys[] = $item['key'];
        }
    }

    // Own account, always. Somebody else's access, only with the permission.
    expect($keys)->toContain('two-factor', 'sessions')
        ->and($keys)->not->toContain('users');

    // No heading with nothing under it: an empty group reads as a screen that failed to
    // load rather than as a permission this person does not have.
    foreach ($visible as $group) {
        expect($group['items'])->not->toBeEmpty();
    }
});
