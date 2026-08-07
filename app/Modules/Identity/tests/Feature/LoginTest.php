<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = tenantUrl($this->tenant);

    $this->user = app(TenantContext::class)->runFor(
        $this->tenant,
        fn () => User::factory()->create(['mobile' => '09121234567'])
    );
});

it('logs a user in with the right credentials', function (): void {
    $this->post($this->url.'/login', [
        'mobile' => '09121234567',
        'password' => 'password',
    ])->assertRedirect(route('dashboard'));

    expect(auth()->check())->toBeTrue();
});

it('accepts Persian digits in the mobile field', function (): void {
    $this->post($this->url.'/login', [
        'mobile' => '۰۹۱۲۱۲۳۴۵۶۷',
        'password' => 'password',
    ])->assertRedirect(route('dashboard'));
});

it('rejects a wrong password', function (): void {
    $this->post($this->url.'/login', [
        'mobile' => '09121234567',
        'password' => 'not-the-password',
    ])->assertSessionHasErrors('mobile');

    expect(auth()->check())->toBeFalse();
});

it('gives the same message for an unknown user as for a wrong password', function (): void {
    // Distinguishing them would let anyone enumerate which staff work at a shop.
    $unknown = $this->post($this->url.'/login', [
        'mobile' => '09120000000',
        'password' => 'whatever',
    ]);

    $wrongPassword = $this->post($this->url.'/login', [
        'mobile' => '09121234567',
        'password' => 'wrong',
    ]);

    expect($unknown->getSession()->get('errors')?->first('mobile'))
        ->toBe($wrongPassword->getSession()->get('errors')?->first('mobile'));
});

it('refuses a deactivated account', function (): void {
    app(TenantContext::class)->runFor(
        $this->tenant,
        fn () => $this->user->forceFill(['is_active' => false])->save()
    );

    $this->post($this->url.'/login', [
        'mobile' => '09121234567',
        'password' => 'password',
    ])->assertSessionHasErrors('mobile');

    expect(auth()->check())->toBeFalse();
});

it('records the login timestamp', function (): void {
    $this->post($this->url.'/login', ['mobile' => '09121234567', 'password' => 'password']);

    $fresh = app(TenantContext::class)->runFor($this->tenant, fn () => $this->user->fresh());

    expect($fresh?->last_login_at)->not->toBeNull();
});

it('throttles repeated failures', function (): void {
    foreach (range(1, 5) as $ignored) {
        $this->post($this->url.'/login', ['mobile' => '09121234567', 'password' => 'wrong']);
    }

    $response = $this->post($this->url.'/login', ['mobile' => '09121234567', 'password' => 'wrong']);

    expect($response->getSession()->get('errors')?->first('mobile'))
        ->toContain('تلاش‌های ناموفق زیاد');
});

it('scopes the throttle to the tenant, so one shop cannot lock out another', function (): void {
    // A shop under attack must not be able to lock the same mobile number out of a
    // different shop — that would turn one compromised tenant into a denial of
    // service against every other tenant with overlapping staff.
    $other = Tenant::factory()->withDomain()->create();

    app(TenantContext::class)->runFor(
        $other,
        fn () => User::factory()->create(['mobile' => '09121234567'])
    );

    foreach (range(1, 6) as $ignored) {
        $this->post($this->url.'/login', ['mobile' => '09121234567', 'password' => 'wrong']);
    }

    $this->post(tenantUrl($other, '/login'), [
        'mobile' => '09121234567',
        'password' => 'password',
    ])->assertRedirect(route('dashboard'));
});

it('clears the throttle on a successful login', function (): void {
    $this->post($this->url.'/login', ['mobile' => '09121234567', 'password' => 'wrong']);
    $this->post($this->url.'/login', ['mobile' => '09121234567', 'password' => 'password']);

    expect(RateLimiter::attempts('login|'.$this->tenant->getKey().'|09121234567|127.0.0.1'))->toBe(0);
});

it('logs out', function (): void {
    $this->actingAs($this->user)
        ->post($this->url.'/logout')
        ->assertRedirect(route('login'));

    expect(auth()->check())->toBeFalse();
});
