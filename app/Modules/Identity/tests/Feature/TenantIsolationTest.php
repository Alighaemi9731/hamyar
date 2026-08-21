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

it('serves one login page that names no shop, and never guesses a tenant', function (): void {
    /*
    | This assertion was INVERTED by ADR 0017, not dropped.
    |
    | It used to read "each tenant gets its own login page, and an unknown host 404s".
    | Both halves were properties of the hostname: the page knew which shop it served
    | because it was served at that shop's address, and a typo'd address was nothing at
    | all. There is no per-shop address any more, so neither half can be asserted — and
    | a test that merely stopped asserting them is the failure mode golden rule 8 and
    | ADR 0017 both name.
    |
    | The replacement is the mirror image and worth the same. `LoginController::create()`
    | states the new rule — the tenant is a RESULT of authenticating, not context this
    | page already has — and that rule has two testable edges:
    |
    |   1. The page names NO shop. A login form that greeted a visitor with a shop name
    |      would be answering "does this shop exist?" to anyone who asked for the form,
    |      which is the enumeration ADR 0017 already accepts a narrower version of on
    |      `mobile` and must not widen.
    |   2. Asking for the form pins NOTHING. `ResolveTenant` reads `session('tenant_id')`
    |      on every request and nothing outside the login POST may write it; the GET is
    |      the most likely place for that rule to be broken by accident.
    |
    | Blade rather than Inertia, incidentally (ADR 0016 — the public surfaces share one
    | design language), which is why these are string assertions on the body.
    */
    $this->get(appUrl('/login'))
        ->assertOk()
        ->assertDontSee('Alpha', false)
        ->assertDontSee('Beta', false)
        ->assertSessionMissing('tenant_id');

    // And the surviving half of the old 404: never a fallback to a default tenant.
    // With nothing pinned the application is unreachable — it does not pick a shop.
    $this->get(appUrl('/dashboard'))->assertRedirect(appUrl('/login'));
});

it('logs out a session presented to the wrong tenant', function (): void {
    /** @var User $alphaUser */
    $alphaUser = $this->context->runFor($this->alpha, fn () => User::factory()->create());

    /*
    | The attack: a session established at shop A, presented as shop B. Laravel resolves
    | the stored user id through B's tenant-scoped provider, and because ids are
    | sequential and every shop starts at 1, B very likely HAS a user with that id —
    | silently authenticating the visitor as that person.
    |
    | ADR 0017 makes this test MORE important, not less. It used to have a layer in
    | front of it: host-only session cookies meant a cookie issued at `a.<apex>` was
    | never even sent to `b.<apex>`, so `tenant.user` was the second line of a pair. One
    | address for every shop removes the first line entirely, and the session's own
    | `tenant_id` now carries the whole weight. This is the test of that weight.
    |
    | Forging the state takes two steps, and their ORDER is the whole test.
    | `Tests\TestCase::actingAs()` writes the user's own `tenant_id` into the session,
    | so signing in as alpha's user pins alpha. `actingForTenant()` then overwrites that
    | key with beta — the only place in the suite allowed to write it. Reversed, the
    | sign-in would restore alpha and the request would simply succeed, proving nothing.
    */
    $this->actingAs($alphaUser);

    actingForTenant($this->beta)
        ->get(appUrl('/dashboard'))
        ->assertRedirect(appUrl('/login'));

    // Rejected is not enough: `EnsureUserBelongsToTenant` tears the session down, so
    // the next request cannot retry with the same cookie.
    expect(auth()->check())->toBeFalse();
});

it('lets a user reach their own tenant dashboard', function (): void {
    /** @var User $alphaUser */
    $alphaUser = $this->context->runFor($this->alpha, fn () => User::factory()->create());

    $this->actingAs($alphaUser)
        ->get(appUrl('/dashboard'))
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
