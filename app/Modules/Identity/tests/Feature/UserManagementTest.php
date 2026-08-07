<?php

declare(strict_types=1);

use App\Modules\Identity\Models\Invitation;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = tenantUrl($this->tenant);

    app(TenantProvisioner::class)->seedRoles($this->tenant);

    [$this->owner, $this->seller] = app(TenantContext::class)->runFor($this->tenant, function (): array {
        $owner = User::factory()->create(['name' => 'مالک']);
        $owner->assignRole('Owner');

        $seller = User::factory()->create(['name' => 'فروشنده']);
        $seller->assignRole('Salesperson');

        return [$owner, $seller];
    });
});

/* ------------------------------------------------------------ authorization -- */

it('lets an Owner see the users screen', function (): void {
    $this->actingAs($this->owner)->get($this->url.'/settings/users')->assertOk();
});

it('refuses the users screen to a Salesperson', function (): void {
    // Policy-driven, not role-driven: a shop that grants users.view to its
    // Salesperson gets that behaviour instead.
    $this->actingAs($this->seller)->get($this->url.'/settings/users')->assertForbidden();
});

it('refuses the activity log to a Salesperson', function (): void {
    $this->actingAs($this->seller)->get($this->url.'/settings/activity')->assertForbidden();
});

it('lets an Owner read the activity log', function (): void {
    $this->actingAs($this->owner)->get($this->url.'/settings/activity')->assertOk();
});

it('does not let anyone assign roles to themselves', function (): void {
    // Otherwise a Manager promotes themselves to Owner, which is the shortest path
    // from "can manage staff" to "owns the shop".
    $this->actingAs($this->owner)
        ->put($this->url.'/settings/users/'.$this->owner->getKey().'/roles', ['roles' => ['Owner']])
        ->assertForbidden();
});

/* ---------------------------------------------------------------- invitations -- */

it('creates an invitation and stores only the token hash', function (): void {
    $this->actingAs($this->owner)->post($this->url.'/settings/users/invite', [
        'name' => 'کاربر تازه',
        'mobile' => '09121110000',
        'role' => 'Cashier',
    ])->assertRedirect();

    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $invitation = Invitation::query()->firstOrFail();

        expect($invitation->mobile)->toBe('09121110000');
        expect($invitation->role)->toBe('Cashier');
        expect($invitation->isPending())->toBeTrue();
        // 64 hex chars = sha256; a plaintext token would be shorter and non-hex.
        expect($invitation->getAttribute('token_hash'))->toMatch('/^[0-9a-f]{64}$/');
    });
});

it('rejects an invitation for a mobile that already works here', function (): void {
    $existing = app(TenantContext::class)->runFor(
        $this->tenant,
        fn (): ?string => User::query()->firstOrFail()->mobile
    );

    $this->actingAs($this->owner)->post($this->url.'/settings/users/invite', [
        'name' => 'تکراری',
        'mobile' => $existing,
        'role' => 'Cashier',
    ])->assertSessionHasErrors('mobile');
});

it('lets an invited user set a password and join', function (): void {
    ['token' => $token, 'hash' => $hash] = Invitation::mintToken();

    app(TenantContext::class)->runFor($this->tenant, fn () => Invitation::query()->create([
        'name' => 'همکار تازه',
        'mobile' => '09122220000',
        'role' => 'Technician',
        'token_hash' => $hash,
        'expires_at' => now()->addDays(7),
    ]));

    $this->get($this->url.'/invitations/accept?token='.$token)->assertOk();

    $this->post($this->url.'/invitations/accept', [
        'token' => $token,
        'password' => 'joining-secret-1',
        'password_confirmation' => 'joining-secret-1',
    ])->assertRedirect(route('dashboard'));

    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $user = User::query()->where('mobile', '09122220000')->firstOrFail();

        expect($user->hasRole('Technician'))->toBeTrue();
        expect(Invitation::query()->firstOrFail()->accepted_at)->not->toBeNull(); // @phpstan-ignore-line
    });
});

it('refuses an invitation token twice', function (): void {
    ['token' => $token, 'hash' => $hash] = Invitation::mintToken();

    app(TenantContext::class)->runFor($this->tenant, fn () => Invitation::query()->create([
        'name' => 'یک‌بارمصرف',
        'mobile' => '09123330000',
        'role' => 'Cashier',
        'token_hash' => $hash,
        'expires_at' => now()->addDays(7),
    ]));

    $payload = [
        'token' => $token,
        'password' => 'joining-secret-1',
        'password_confirmation' => 'joining-secret-1',
    ];

    $this->post($this->url.'/invitations/accept', $payload);

    // Accepting logs the new user in, so log out first — otherwise the second attempt
    // is bounced by `guest` and we would be asserting the wrong thing entirely.
    auth()->logout();
    session()->flush();

    // Second use must fail; otherwise a forwarded link is an open door.
    $this->post($this->url.'/invitations/accept', $payload)->assertSessionHasErrors('token');

    expect(
        app(TenantContext::class)->runFor(
            $this->tenant,
            fn (): int => User::query()->where('mobile', '09123330000')->count()
        )
    )->toBe(1);
});

it('does not accept another shop invitation token', function (): void {
    pest()->group('isolation');

    $other = Tenant::factory()->withDomain()->create();

    ['token' => $token, 'hash' => $hash] = Invitation::mintToken();

    app(TenantContext::class)->runFor($other, fn () => Invitation::query()->create([
        'name' => 'غریبه',
        'mobile' => '09124440000',
        'role' => 'Cashier',
        'token_hash' => $hash,
        'expires_at' => now()->addDays(7),
    ]));

    // Presented on OUR host, the token belongs to a shop this context cannot see.
    $this->get($this->url.'/invitations/accept?token='.$token)->assertRedirect(route('login'));
});

/* -------------------------------------------------------------- last owner -- */

it('refuses to remove the last Owner', function (): void {
    // A shop with no Owner cannot manage its own billing or staff — an unrecoverable
    // state without support intervention.
    $manager = app(TenantContext::class)->runFor($this->tenant, function (): User {
        $manager = User::factory()->create();
        $manager->assignRole('Manager');

        return $manager;
    });

    $this->actingAs($manager)
        ->put($this->url.'/settings/users/'.$this->owner->getKey().'/roles', ['roles' => ['Cashier']])
        ->assertSessionHasErrors('roles');

    app(TenantContext::class)->runFor(
        $this->tenant,
        fn () => expect($this->owner->fresh()?->hasRole('Owner'))->toBeTrue()
    );
});

/* ---------------------------------------------------------------- sessions -- */

it('lists and revokes sessions', function (): void {
    app(TenantContext::class)->runFor($this->tenant, fn () => DB::table('sessions')->insert([
        'id' => 'other-device-session',
        'tenant_id' => $this->tenant->getKey(),
        'user_id' => $this->owner->getKey(),
        'payload' => '',
        'last_activity' => time(),
    ]));

    $this->actingAs($this->owner)->get($this->url.'/settings/sessions')->assertOk();

    $this->actingAs($this->owner)
        ->delete($this->url.'/settings/sessions/other-device-session')
        ->assertRedirect();

    expect(DB::table('sessions')->where('id', 'other-device-session')->exists())->toBeFalse();
});

it('will not revoke a session belonging to someone else', function (): void {
    app(TenantContext::class)->runFor($this->tenant, fn () => DB::table('sessions')->insert([
        'id' => 'not-yours',
        'tenant_id' => $this->tenant->getKey(),
        'user_id' => $this->seller->getKey(),
        'payload' => '',
        'last_activity' => time(),
    ]));

    $this->actingAs($this->owner)->delete($this->url.'/settings/sessions/not-yours');

    // Scoped by user_id, so a guessed id cannot end a stranger's session.
    expect(DB::table('sessions')->where('id', 'not-yours')->exists())->toBeTrue();
});
