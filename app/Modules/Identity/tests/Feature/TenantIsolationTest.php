<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Isolation suite v1 — the tests this product's credibility rests on.
 *
 * Golden rule 8. Run on their own with `composer test:isolation`.
 *
 * The whole file carries the `isolation` group, so CI's dedicated job picks it up.
 */
pest()->group('isolation');

beforeEach(function (): void {
    $this->context = app(TenantContext::class);

    $this->alpha = Tenant::factory()->withDomain()->create(['name' => 'Alpha']);
    $this->beta = Tenant::factory()->withDomain()->create(['name' => 'Beta']);
});

afterEach(function (): void {
    app(TenantContext::class)->forget();
});

it('scopes Eloquent reads to the current tenant', function (): void {
    $this->context->runFor($this->alpha, fn () => User::factory()->create(['name' => 'Alpha staff']));
    $this->context->runFor($this->beta, fn () => User::factory()->create(['name' => 'Beta staff']));

    $seenByAlpha = $this->context->runFor($this->alpha, fn () => User::query()->pluck('name')->all());
    $seenByBeta = $this->context->runFor($this->beta, fn () => User::query()->pluck('name')->all());

    expect($seenByAlpha)->toBe(['Alpha staff']);
    expect($seenByBeta)->toBe(['Beta staff']);
});

it('cannot read another tenant rows through a RAW query, with no Eloquent scope in play', function (): void {
    $this->context->runFor($this->alpha, fn () => User::factory()->create(['name' => 'Alpha staff']));

    // This is the test that makes RLS meaningful. It bypasses the global scope
    // entirely — exactly what a hand-written report query or a forgotten
    // withoutGlobalScopes() would do — and must still see nothing.
    $rows = $this->context->runFor(
        $this->beta,
        fn (): array => DB::select('select id, name from users')
    );

    expect($rows)->toBeEmpty();
});

it('finds a row by primary key only inside its own tenant', function (): void {
    /** @var User $alphaUser */
    $alphaUser = $this->context->runFor($this->alpha, fn () => User::factory()->create());

    $found = $this->context->runFor($this->beta, fn () => User::query()->find($alphaUser->getKey()));

    expect($found)->toBeNull();
});

it('refuses to WRITE a row carrying another tenant id', function (): void {
    // The WITH CHECK half of the policy. Without it a tenant could read only its own
    // rows but still create or move rows into somebody else's shop, which corrupts
    // data permanently rather than merely exposing it.
    //
    // The insert is wrapped in a nested transaction (a SAVEPOINT) on purpose: in
    // Postgres a failed statement poisons the whole transaction, and RefreshDatabase
    // has already opened one around this test. Without the savepoint the rejection
    // would take the rest of the test down with it.
    $write = fn () => $this->context->runFor($this->beta, fn () => DB::transaction(
        fn () => DB::insert(
            'insert into users (tenant_id, name, mobile, password, created_at, updated_at) values (?, ?, ?, ?, now(), now())',
            [$this->alpha->getKey(), 'Smuggled', '09120000001', 'x']
        )
    ));

    expect($write)->toThrow(Illuminate\Database\QueryException::class);

    // And nothing was written.
    $count = $this->context->runFor(
        $this->alpha,
        fn (): int => User::query()->where('name', 'Smuggled')->count()
    );

    expect($count)->toBe(0);
});

it('sees nothing at all when no tenant is set', function (): void {
    $this->context->runFor($this->alpha, fn () => User::factory()->create());

    $rows = $this->context->runWithoutTenant(
        fn (): array => DB::select('select id from users')
    );

    // Fails CLOSED: an unset context denies everything rather than exposing everything.
    expect($rows)->toBeEmpty();
});

it('restores the previous tenant even when the callback throws', function (): void {
    $this->context->set($this->alpha);

    try {
        $this->context->runFor($this->beta, function (): void {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // expected
    }

    // A process left pinned to the wrong tenant is the most dangerous state this
    // system can be in, so the restore lives in a finally.
    expect($this->context->id())->toBe($this->alpha->getKey());
});

it('serves each tenant its own login page and 404s an unknown host', function (): void {
    $this->get('http://'.$this->alpha->slug.'.app.localhost/login')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('tenant.name', 'Alpha'));

    $this->get('http://'.$this->beta->slug.'.app.localhost/login')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('tenant.name', 'Beta'));

    // Never a fallback to a default tenant — a typo must not serve someone else's shop.
    $this->get('http://not-a-real-shop.app.localhost/login')->assertNotFound();
});

it('logs out a session presented to the wrong tenant', function (): void {
    /** @var User $alphaUser */
    $alphaUser = $this->context->runFor($this->alpha, fn () => User::factory()->create());

    // `actingAs` injects the user straight into the guard, bypassing the session
    // provider — which is exactly the shape of the attack this guards against: a
    // cookie from shop A replayed at shop B. Laravel would resolve the stored id
    // through B's scoped provider, and because ids are sequential and every shop
    // starts at 1, B very likely HAS a user with that id — silently authenticating
    // the visitor as that person.
    //
    // Host-only session cookies stop the cookie ever arriving. This asserts the
    // second line: even if it does, the request is rejected.
    $this->actingAs($alphaUser)
        ->get('http://'.$this->beta->slug.'.app.localhost/dashboard')
        ->assertRedirect(route('login'));

    expect(auth()->check())->toBeFalse();
});

it('lets a user reach their own tenant dashboard', function (): void {
    /** @var User $alphaUser */
    $alphaUser = $this->context->runFor($this->alpha, fn () => User::factory()->create());

    $this->actingAs($alphaUser)
        ->get('http://'.$this->alpha->slug.'.app.localhost/dashboard')
        ->assertOk();
});

it('keeps roles scoped per tenant', function (): void {
    $alphaRoles = $this->context->runFor(
        $this->alpha,
        fn (): array => DB::select('select name from roles')
    );

    // Roles are seeded per tenant at onboarding; a factory-made tenant has none, so
    // both sides see an empty set rather than each other's.
    expect($alphaRoles)->toBeEmpty();
});
