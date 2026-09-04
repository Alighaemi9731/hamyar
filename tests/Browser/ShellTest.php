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
 * The shell's rail: the sidebar collapsed to one icon wide, remembered per browser.
 *
 * Measured rather than asserted from classes, because the whole point is the width the
 * page gets back; and reloaded, because a preference that lasts one visit is a toggle
 * somebody presses every morning.
 */
pest()->group('browser');

beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->create();

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

/**
 * Measured a beat after the last action: React commits the toggle asynchronously and
 * the width animates on `--duration-base`, so a synchronous read sees the old frame.
 */
const RAIL_MEASURE = <<<'JS'
    new Promise((resolve) => setTimeout(() => resolve(JSON.stringify({
        width: Math.round(document.querySelector('aside').getBoundingClientRect().width),
        rail: document.querySelector('aside').dataset.rail,
        // Every link keeps its name when only its icon shows.
        unnamed: [...document.querySelectorAll('#sidebar-nav a')]
            .filter((a) => a.textContent.trim() === '').length,
    })), 500))
JS;

it('collapses the sidebar to a rail, keeps every link named, and remembers it across a reload', function (): void {
    $this->actingAs($this->owner);

    // Resolved once, by keeping the return of the first call: every call on the pending
    // page object opens a fresh visit in a fresh context, so a click on one visit and a
    // measurement on the next never meet — and a fresh context has no localStorage.
    $page = visit('/dashboard')->inLightMode()->on()->desktop()->assertNoJavascriptErrors();

    /** @var array{width: int, rail: string, unnamed: int} $before */
    $before = json_decode((string) $page->script(RAIL_MEASURE), true);
    expect($before['rail'])->toBe('open');
    expect($before['width'])->toBe(248);

    // By the nav it controls rather than by its label: the label carries a ZWNJ that a
    // selector typed by hand may or may not, and the relation is the contract anyway.
    // A real pointer click through Playwright, with its actionability wait, rather than
    // `element.click()` from an evaluated script: the latter fired and React never saw it.
    $page->click('button[aria-controls="sidebar-nav"]');

    /** @var array{width: int, rail: string, unnamed: int} $after */
    $after = json_decode((string) $page->script(RAIL_MEASURE), true);
    expect($after['rail'])->toBe('collapsed', 'The toggle did not collapse the rail: '.json_encode($after));
    expect($after['width'])->toBe(64);
    expect($after['unnamed'])->toBe(0);

    $page->refresh();
    $page->assertNoJavascriptErrors();

    /** @var array{width: int, rail: string, unnamed: int} $again */
    $again = json_decode((string) $page->script(RAIL_MEASURE), true);
    expect($again['rail'])->toBe('collapsed');
    expect($again['width'])->toBe(64);
});
