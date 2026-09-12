<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Moadian\Models\MoadianSetting;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;

/**
 * The credentials screen — the one the landing page has been promising.
 *
 * `lang/fa/landing.php` tells every visitor «شناسه و کلید حافظهٔ مالیاتی را یک بار در
 * تنظیمات وارد می‌کنید», and until this shipped there was no such screen: the table, the
 * model and the encryption were all in place and nothing reached them. That is the exact
 * shape `CLAUDE.md` refuses to let anyone tick a box for — user-facing behaviour no route
 * and no screen can reach.
 *
 * The assertion this file exists for is the third one: **the private key must never leave
 * the server.** Everything else here is ordinary form coverage.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();

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

it('renders the screen the landing page promises', function (): void {
    $this->actingAs($this->owner)
        ->get($this->url.'/moadian/settings')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Moadian::Moadian/Settings'));
});

it('saves the credentials a shopkeeper types', function (): void {
    $this->actingAs($this->owner)
        ->put($this->url.'/moadian/settings', [
            'memory_id' => 'A1B2C3D4',
            'economic_code' => '411111111111',
            'private_key' => 'the-real-key',
            'is_enabled' => true,
        ])
        ->assertRedirect();

    $stored = app(TenantContext::class)->runFor($this->tenant, fn () => MoadianSetting::query()->firstOrFail());

    expect($stored->memory_id)->toBe('A1B2C3D4')
        ->and($stored->economic_code)->toBe('411111111111')
        ->and($stored->is_enabled)->toBeTrue()
        // Decrypted by the cast on read; what is on disk is ciphertext.
        ->and($stored->private_key)->toBe('the-real-key');
});

it('never sends the private key to the browser', function (): void {
    app(TenantContext::class)->runFor($this->tenant, fn () => MoadianSetting::query()->create([
        'memory_id' => 'A1B2C3D4',
        'private_key' => 'super-secret-key-material',
        'is_enabled' => true,
    ]));

    $response = $this->actingAs($this->owner)->get($this->url.'/moadian/settings')->assertOk();

    /*
    | Asserted against the WHOLE response, not against the prop.
    |
    | `$hidden` on the model keeps the key out of `toArray()`, which is the path an Inertia
    | prop takes — but the lesson this pairing records (see `MoadianSetting`'s docblock, and
    | the repair-passcode scar behind it) is that the leak is never on the path you hardened.
    | It is on some other one: a validation error echoing old input, a flash message, a
    | serialised form state. So this looks at the bytes that reach the browser.
    */
    expect($response->getContent())->not->toContain('super-secret-key-material');

    $response->assertInertia(fn ($page) => $page
        ->where('has_private_key', true)
        ->missing('private_key'));
});

it('treats a blank key as "unchanged", not as "clear it"', function (): void {
    app(TenantContext::class)->runFor($this->tenant, fn () => MoadianSetting::query()->create([
        'memory_id' => 'OLD',
        'private_key' => 'the-key-that-must-survive',
        'is_enabled' => true,
    ]));

    /*
    | The form ALWAYS renders that field empty, because the key is never sent back. So an
    | empty submit is what arrives every time somebody edits the economic code beside it.
    | If blank meant "clear", a shop would lose its credentials as a side effect of an
    | unrelated save — and would find out at audit time, which is the failure this whole
    | module was built to prevent.
    */
    $this->actingAs($this->owner)
        ->put($this->url.'/moadian/settings', [
            'memory_id' => 'NEW',
            'economic_code' => '411111111111',
            'private_key' => '',
            'is_enabled' => true,
        ])
        ->assertRedirect();

    $stored = app(TenantContext::class)->runFor($this->tenant, fn () => MoadianSetting::query()->firstOrFail());

    expect($stored->memory_id)->toBe('NEW')
        ->and($stored->private_key)->toBe('the-key-that-must-survive');
});

it('refuses a user without settings.update', function (): void {
    $clerk = app(TenantContext::class)->runFor($this->tenant, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Cashier');

        return $user;
    });

    $this->actingAs($clerk)->get($this->url.'/moadian/settings')->assertForbidden();

    $this->actingAs($clerk)
        ->put($this->url.'/moadian/settings', ['is_enabled' => true])
        ->assertForbidden();
});

it('keeps one shop out of another shop\'s credentials', function (): void {
    // Golden rule 8: every tenant-scoped endpoint gets this, and a tax credential is the
    // last row in this product anybody should be able to reach across a tenant boundary.
    $other = Tenant::factory()->withDomain()->create();
    subscribe($other, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($other);

    app(TenantContext::class)->runFor($this->tenant, fn () => MoadianSetting::query()->create([
        'memory_id' => 'SHOP-A-ONLY',
        'private_key' => 'shop-a-key',
        'is_enabled' => true,
    ]));

    $intruder = app(TenantContext::class)->runFor($other, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Owner');

        return $user;
    });

    $response = $this->actingAs($intruder)->get($this->url.'/moadian/settings')->assertOk();

    // B sees an empty form, not A's row — RLS does the work and this proves it reaches here.
    expect($response->getContent())->not->toContain('SHOP-A-ONLY');

    $response->assertInertia(fn ($page) => $page->where('memory_id', null)->where('has_private_key', false));
});
