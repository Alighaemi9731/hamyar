<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\PasswordResetService;
use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = tenantUrl($this->tenant);

    $this->user = app(TenantContext::class)->runFor(
        $this->tenant,
        fn () => User::factory()->create(['mobile' => '09121234567'])
    );
});

it('issues a token for a known mobile', function (): void {
    $token = app(TenantContext::class)->runFor(
        $this->tenant,
        fn (): ?string => app(PasswordResetService::class)->issue('09121234567')
    );

    expect($token)->toBeString();
});

it('stores only the token HASH, never the token', function (): void {
    $token = app(TenantContext::class)->runFor(
        $this->tenant,
        fn (): ?string => app(PasswordResetService::class)->issue('09121234567')
    );

    $stored = app(TenantContext::class)->runFor(
        $this->tenant,
        fn () => DB::table('password_reset_tokens')->value('token')
    );

    // A database leak must not yield working reset links.
    expect($stored)->not->toBe($token);
    expect($stored)->toBe(hash('sha256', (string) $token));
});

it('answers identically for a known and an unknown number', function (): void {
    // Otherwise the form is an oracle for "does this person work at this shop?".
    $this->post($this->url.'/forgot-password', ['identifier' => '09121234567']);
    $known = session('success');

    session()->forget('success');

    $this->post($this->url.'/forgot-password', ['identifier' => '09120000000']);
    $unknown = session('success');

    expect($known)->toBe($unknown)->not->toBeNull();
});

it('resets the password with a valid token', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $token = app(PasswordResetService::class)->issue('09121234567');

        expect(app(PasswordResetService::class)->reset('09121234567', (string) $token, 'brand-new-secret-1'))
            ->toBeTrue();

        expect(Hash::check('brand-new-secret-1', User::query()->firstOrFail()->password))
            ->toBeTrue();
    });
});

it('refuses a token that has already been used', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $service = app(PasswordResetService::class);
        $token = (string) $service->issue('09121234567');

        expect($service->reset('09121234567', $token, 'first-secret-value-1'))->toBeTrue();
        expect($service->reset('09121234567', $token, 'second-secret-value-2'))->toBeFalse();
    });
});

it('refuses an expired token', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $service = app(PasswordResetService::class);
        $token = (string) $service->issue('09121234567');

        $this->travel(PasswordResetService::TOKEN_TTL_MINUTES + 5)->minutes();

        expect($service->reset('09121234567', $token, 'too-late-secret-1'))->toBeFalse();
    });
});

it('kills every other session on reset', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        DB::table('sessions')->insert([
            'id' => 'stale-session-id',
            'tenant_id' => $this->tenant->getKey(),
            'user_id' => $this->user->getKey(),
            'payload' => '',
            'last_activity' => time(),
        ]);

        $service = app(PasswordResetService::class);
        $token = (string) $service->issue('09121234567');
        $service->reset('09121234567', $token, 'rotated-secret-value-1');

        // A reset is what someone does when they think they are compromised; leaving
        // the attacker's session alive would defeat the point.
        expect(DB::table('sessions')->where('id', 'stale-session-id')->exists())->toBeFalse();
    });
});

it('does not let a token from one shop reset an account at another', function (): void {
    pest()->group('isolation');

    $other = Tenant::factory()->withDomain()->create();

    app(TenantContext::class)->runFor(
        $other,
        fn () => User::factory()->create(['mobile' => '09121234567'])
    );

    $token = app(TenantContext::class)->runFor(
        $this->tenant,
        fn (): ?string => app(PasswordResetService::class)->issue('09121234567')
    );

    $crossed = app(TenantContext::class)->runFor(
        $other,
        fn (): bool => app(PasswordResetService::class)->reset('09121234567', (string) $token, 'cross-tenant-1')
    );

    expect($crossed)->toBeFalse();
});
