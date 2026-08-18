<?php

declare(strict_types=1);

use App\Modules\Platform\Models\Tenant;
use App\Modules\Sales\Models\SalesInvoice;
use App\Support\Tenancy\TenantContext;

/**
 * The load fixture, tested for the two properties that make it safe to run.
 *
 * It is a development tool, so it does not need the coverage a shipped feature does. It
 * does need these, because both failures are expensive and neither is visible at the
 * moment it happens:
 *
 * 1. **It must not be able to run in production.** A command that writes two million
 *    invoices is one nobody should be able to point at customer data by mistyping an
 *    environment.
 * 2. **`--fresh` must remove only its own shops.** It is the button somebody presses
 *    between runs, on a database that also holds the demo tenant and whatever they were
 *    working on.
 *
 * Volumes are kept tiny here on purpose: the fixture's *size* is a property of the
 * seeder it delegates to and is measured by hand (19 minutes for fifty shops), not
 * asserted in a suite that has to stay fast.
 */
it('refuses to run in production without being forced', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    $this->artisan('platform:seed-volume', ['--tenants' => 1, '--invoices' => 1])
        ->expectsOutputToContain('Refusing to seed load-test data into production')
        ->assertFailed();

    expect(Tenant::query()->where('slug', 'like', 'load-test-%')->count())->toBe(0);
});

it('seeds shops that each own their own trading history', function (): void {
    $this->artisan('platform:seed-volume', ['--tenants' => 2, '--invoices' => 5])->assertSuccessful();

    $shops = Tenant::query()->where('slug', 'like', 'load-test-%')->orderBy('id')->get();

    expect($shops)->toHaveCount(2);

    // The point of a multi-tenant fixture is that the neighbour exists and is invisible.
    // A fixture whose shops could see each other would make every plan measured against
    // it a plan for a database nobody runs.
    foreach ($shops as $shop) {
        $mine = app(TenantContext::class)->runFor(
            $shop,
            fn (): int => SalesInvoice::query()->count(),
        );

        expect($mine)->toBeGreaterThan(0)
            ->and($mine)->toBeLessThan(20, "Shop {$shop->slug} can see its neighbour's invoices.");
    }
});

it('removes only its own shops when asked to start fresh', function (): void {
    $keep = Tenant::factory()->create(['slug' => 'a-real-shop']);

    $this->artisan('platform:seed-volume', ['--tenants' => 1, '--invoices' => 1])->assertSuccessful();
    $this->artisan('platform:seed-volume', ['--tenants' => 1, '--invoices' => 1, '--fresh' => true])
        ->expectsOutputToContain('Removing 1 shops from a previous run.')
        ->assertSuccessful();

    expect(Tenant::query()->where('slug', 'like', 'load-test-%')->count())->toBe(1)
        // The assertion that matters: the button between runs did not take the shop
        // somebody was working on.
        ->and(Tenant::query()->whereKey($keep->getKey())->exists())->toBeTrue();
});
