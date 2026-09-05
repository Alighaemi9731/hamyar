<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Domain;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;
use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\Webpage;

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
        links: document.querySelectorAll('#sidebar-nav a').length,
        // On the rail the tile IS the row, so a link that lost its mark is a link
        // nobody can pick out — and exactly one of them says which page you are on.
        chips: document.querySelectorAll('#sidebar-nav a > .nav-chip').length,
        activeChips: document.querySelectorAll('#sidebar-nav a > .nav-chip[data-active="true"]').length,
    })), 500))
JS;

/**
 * @return array{width: int, rail: string, unnamed: int, links: int, chips: int, activeChips: int}
 */
function railState(Webpage|AwaitableWebpage $page): array
{
    // Asserted rather than cast: `script()` returns `mixed`, and a run where the page
    // never answered would otherwise decode to null and read as "still open".
    $result = $page->script(RAIL_MEASURE);

    expect($result)->toBeString('The rail measurement must return a JSON string.');

    /** @var string $result */
    /** @var array{width: int, rail: string, unnamed: int, links: int, chips: int, activeChips: int} $measured */
    $measured = json_decode($result, true);

    return $measured;
}

it('collapses the sidebar to a rail, keeps every link named, and remembers it across a reload', function (): void {
    $this->actingAs($this->owner);

    // Resolved once, by keeping the return of the first call: calls on the pending page
    // object can land on separate visits, so a click on one and a measurement on the
    // next never meet — and a fresh context has no localStorage.
    $page = visit('/dashboard')->inLightMode()->on()->desktop()->assertNoJavascriptErrors();

    $before = railState($page);
    expect($before['rail'])->toBe('open');
    expect($before['width'])->toBe(248);

    // By the nav it controls rather than by its label: the label carries a ZWNJ that a
    // selector typed by hand may or may not, and the relation is the contract anyway.
    $page->click('button[aria-controls="sidebar-nav"]');

    $after = railState($page);
    expect($after['rail'])->toBe('collapsed', 'The toggle did not collapse the rail: '.json_encode($after));
    expect($after['width'])->toBe(64);
    expect($after['unnamed'])->toBe(0);

    // Collapsed, the icon tile is the whole of the row a shopkeeper aims at: one per
    // link, and the one for the page they are on marked. Asserted here rather than in
    // the open state because this is where losing either would actually cost them —
    // an unmarked rail is nineteen identical squares.
    expect($after['chips'])->toBe($after['links'], 'A rail row is missing its icon tile: '.json_encode($after));
    expect($after['activeChips'])->toBe(1, 'The rail does not mark the current page: '.json_encode($after));

    $page->refresh();
    $page->assertNoJavascriptErrors();

    $again = railState($page);
    expect($again['rail'])->toBe('collapsed');
    expect($again['width'])->toBe(64);
});
