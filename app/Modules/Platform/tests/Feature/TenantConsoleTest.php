<?php

declare(strict_types=1);

use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Spatie\Permission\Models\Role;

pest()->group('isolation');

beforeEach(function (): void {
    $this->alpha = Tenant::factory()->create(['name' => 'Alpha']);
    $this->beta = Tenant::factory()->create(['name' => 'Beta']);
    $this->suspended = Tenant::factory()->suspended()->create(['name' => 'Suspended']);
});

afterEach(fn () => app(TenantContext::class)->forget());

function roleCountFor(Tenant $tenant): int
{
    return app(TenantContext::class)->runFor(
        $tenant,
        fn (): int => Role::query()->count()
    );
}

it('seeds roles for every usable tenant when no --tenant is given', function (): void {
    $this->artisan('tenancy:sync-permissions')->assertSuccessful();

    expect(roleCountFor($this->alpha))->toBe(7);
    expect(roleCountFor($this->beta))->toBe(7);
});

it('skips suspended tenants on an unattended run', function (): void {
    // Writing into a suspended shop is almost never what a maintenance command means.
    $this->artisan('tenancy:sync-permissions')->assertSuccessful();

    expect(roleCountFor($this->suspended))->toBe(0);
});

it('targets a single tenant by slug', function (): void {
    $this->artisan('tenancy:sync-permissions', ['--tenant' => [$this->alpha->slug]])->assertSuccessful();

    expect(roleCountFor($this->alpha))->toBe(7);
    expect(roleCountFor($this->beta))->toBe(0);
});

it('targets a tenant by id', function (): void {
    $this->artisan('tenancy:sync-permissions', ['--tenant' => [(string) $this->beta->getKey()]])->assertSuccessful();

    expect(roleCountFor($this->beta))->toBe(7);
    expect(roleCountFor($this->alpha))->toBe(0);
});

it('accepts a suspended tenant when named explicitly', function (): void {
    // Explicit is different from unattended: naming a suspended shop is a deliberate
    // maintenance act.
    $this->artisan('tenancy:sync-permissions', ['--tenant' => [$this->suspended->slug]])->assertSuccessful();

    expect(roleCountFor($this->suspended))->toBe(7);
});

it('fails when no tenant matches', function (): void {
    $this->artisan('tenancy:sync-permissions', ['--tenant' => ['no-such-shop']])->assertFailed();
});

it('leaves the process with no tenant context afterwards', function (): void {
    $this->artisan('tenancy:sync-permissions')->assertSuccessful();

    // A console process that keeps a tenant pinned would apply it to whatever the
    // next command in a chained deploy step does.
    expect(app(TenantContext::class)->id())->toBeNull();
});
