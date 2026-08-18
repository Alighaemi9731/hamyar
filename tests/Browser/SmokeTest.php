<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Domain;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;

/**
 * The manual walk, run by the machine.
 *
 * Every phase has ended with somebody opening the app and checking the same few things:
 * no sideways scroll at 390 and at 1280, no console errors, the page rendering at all.
 * It has found real defects every time — three in 11b, six in 11c — because a rendered
 * page asks a different question from a passing assertion.
 *
 * It also depends on somebody remembering, which is what this removes. Roadmap 11.1b.
 *
 * ## The shop is given the hostname the server answers on
 *
 * Every screen in this product is resolved from the **hostname**: `ResolveTenant` turns
 * `acme.app.localhost` into a shop and pins it for the request, and a hostname belonging
 * to no tenant is a 404 by design. Pest's HTTP server always binds `127.0.0.1`, so a
 * browser test visiting `/dashboard` arrives as a shop that does not exist.
 *
 * The plugin's `withHost()` looked like the answer and is not — it moves where the
 * server listens, not the Host header the page is fetched with, and the request still
 * arrives as `127.0.0.1`.
 *
 * So the fixture is inverted instead: the tenant is created with `127.0.0.1` as its own
 * primary domain. Nothing is faked — `ResolveTenant` does its real lookup against a real
 * `domains` row and either finds the shop or does not. It is the same trick the product
 * itself relies on (golden rule 1b: tenants resolve by `domains.hostname` rows, and the
 * apex is never a literal in code), which is exactly why it works here.
 *
 * ## Scope, deliberately narrow
 *
 * A **smoke** suite, not a replacement for the walk. It asserts what is mechanical —
 * overflow, console errors, the page rendering — and makes no claim about whether a
 * screen looks right. Judgement stays human; the tripwires do not.
 */
pest()->group('browser');

beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->create();

    // The host Pest's server answers on, given to the shop as its own.
    Domain::query()->create([
        'tenant_id' => $this->tenant->getKey(),
        'hostname' => '127.0.0.1',
        'is_primary' => true,
    ]);

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    $this->owner = app(TenantContext::class)->runFor($this->tenant, function (): User {
        $user = User::factory()->create(['name' => 'مالک']);
        $user->assignRole('Owner');

        return $user;
    });
});

it('renders every main screen with no console error and no sideways scroll', function (string $path, string $device): void {
    $this->actingAs($this->owner);

    // The viewport is chosen at visit time rather than resized afterwards: the plugin
    // builds the browser context from a device profile, and a page already laid out at
    // one width and then resized is not the same thing as a page that loaded at the
    // other — media queries and `dvh` units settle differently.
    $page = $device === 'mobile'
        ? visit($path)->on()->mobile()
        : visit($path)->on()->desktop();

    $page->assertNoJavascriptErrors();

    /*
    | ---------------------------------------------------------------- the wait --
    | This poll is the difference between a test and a decoration.
    |
    | Inertia renders server-side into `data-page` and React mounts from it a beat
    | later. `script()` runs as soon as the load event fires, which is *before* that —
    | so the first version of this file measured an empty `<div id="app">`, found
    | scrollWidth equal to clientWidth on every page at every width, and passed all
    | eight cases. It also passed with a 2000px-wide element planted in the page, and
    | with sixty repetitions of an unbreakable token: green without witness, on the
    | exact suite written to stop that.
    |
    | Polling for a mounted root rather than sleeping a fixed interval: a sleep long
    | enough for CI is dead time locally, and one long enough locally is a flake in CI.
    */
    $result = $page->script(<<<'JS'
        new Promise((resolve) => {
            const deadline = Date.now() + 10000;

            const check = () => {
                const root = document.getElementById('app');
                const mounted = root !== null && root.innerHTML.length > 0;

                if (mounted || Date.now() > deadline) {
                    const el = document.documentElement;

                    resolve(JSON.stringify({
                        mounted,
                        // +1: sub-pixel rounding leaves scrollWidth a fraction over
                        // clientWidth on pages that do not scroll, and a gate that
                        // fires on rounding is a gate somebody switches off.
                        overflows: el.scrollWidth > el.clientWidth + 1,
                        scrollWidth: el.scrollWidth,
                        clientWidth: el.clientWidth,
                    }));

                    return;
                }

                setTimeout(check, 50);
            };

            check();
        })
    JS);

    // Asserted rather than cast: `script()` returns `mixed`, and a run where the page
    // never answered would otherwise decode to null and read as "did not overflow".
    expect($result)->toBeString('The measurement script must return a JSON string.');

    /** @var string $json */
    $json = $result;

    /** @var array{mounted: bool, overflows: bool, scrollWidth: int, clientWidth: int} $measured */
    $measured = json_decode($json, true);

    // Asserted before the overflow check, and first: an unmounted page cannot overflow,
    // so without this every assertion below is true for the wrong reason.
    expect($measured['mounted'])->toBeTrue("[{$path}] never mounted on {$device}; nothing below this line means anything.");

    /*
    | Horizontal overflow is the defect no server-side test can reach. An RTL layout
    | that fits on a laptop and pushes the page sideways on a phone looks correct in
    | every screenshot anybody thought to take, and reads to a shop assistant as a
    | broken app — on the device they actually hold.
    */
    expect($measured['overflows'])->toBeFalse(sprintf(
        '[%s] scrolls sideways on %s: %dpx of content in a %dpx viewport.',
        $path,
        $device,
        $measured['scrollWidth'],
        $measured['clientWidth'],
    ));
})->with([
    'dashboard' => '/dashboard',
    'audit log' => '/settings/activity',
    'products' => '/catalog',
    'users' => '/settings/users',
])->with([
    'mobile',
    'desktop',
]);
