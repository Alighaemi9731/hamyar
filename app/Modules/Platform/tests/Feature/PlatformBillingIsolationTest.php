<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Subscription;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * `subscriptions` and `subscription_invoices` are the one place where a tenant table
 * deliberately skips `BelongsToTenant`, because the Platform module reports across every
 * shop. That exemption is only safe if two things hold, so both are asserted here:
 *
 *   1. RLS still isolates them — a shop cannot read or write another shop's billing.
 *   2. The `app.platform` flag is NARROW. It opens billing and nothing else; a shop's
 *      invoices, customers and stock stay invisible even to the platform.
 *
 * If (2) ever regresses, the escape hatch has quietly become a superuser.
 */
uses()->group('isolation');

beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->alpha = Tenant::factory()->withDomain()->create();
    $this->beta = Tenant::factory()->withDomain()->create();
});

afterEach(fn () => app(TenantContext::class)->forget());

it('hides one shop billing from another', function (): void {
    subscribe($this->alpha, 'enterprise');
    subscribe($this->beta, 'basic');

    $seen = app(TenantContext::class)->runFor(
        $this->beta,
        fn (): array => Subscription::query()->pluck('tenant_id')->all()
    );

    // Not "the query happened to filter" — RLS refuses to return the row at all.
    expect($seen)->toBe([$this->beta->getKey()]);
});

it('refuses a raw insert of a subscription from inside a tenant context', function (): void {
    $plan = DB::table('plans')->where('code', 'enterprise')->value('id');

    app(TenantContext::class)->runFor($this->alpha, function () use ($plan): void {
        // Raw, so no Eloquent scope is involved. This is RLS or nothing.
        //
        // Nested in its own transaction because a rejected write aborts the enclosing
        // one, and RefreshDatabase has already opened it — without the savepoint the
        // teardown fails with "current transaction is aborted" and hides the result.
        expect(fn () => DB::transaction(fn () => DB::table('subscriptions')->insert([
            'tenant_id' => $this->beta->getKey(),
            'plan_id' => $plan,
            'status' => Subscription::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
            // Asserting the RLS message, not just "something threw" — a foreign-key or
            // not-null error would otherwise pass this test while proving nothing.
        ])))->toThrow(QueryException::class, 'violates row-level security policy');
    });
});

it('lets the platform read every shop billing at once', function (): void {
    subscribe($this->alpha, 'enterprise');
    subscribe($this->beta, 'basic');

    $seen = app(TenantContext::class)->runAsPlatform(
        fn (): array => Subscription::query()->pluck('tenant_id')->all()
    );

    expect($seen)->toContain($this->alpha->getKey(), $this->beta->getKey());
});

it('does NOT let the platform flag open ordinary tenant tables', function (): void {
    app(TenantProvisioner::class)->seedRoles($this->alpha);
    app(TenantProvisioner::class)->seedRoles($this->beta);

    app(TenantContext::class)->runFor($this->alpha, fn () => User::factory()->create());
    app(TenantContext::class)->runFor($this->beta, fn () => User::factory()->create());

    // This is the assertion that keeps the exemption honest. `app.platform` is consulted
    // ONLY by the billing policies; `users` has an ordinary policy, so with no tenant
    // set it still resolves to nothing. Were this to return rows, the flag would have
    // become a blanket bypass and golden rule 1 would be worth nothing.
    $seen = app(TenantContext::class)->runAsPlatform(
        fn (): int => DB::table('users')->count()
    );

    expect($seen)->toBe(0);
});

it('clears the platform flag even when the callback throws', function (): void {
    subscribe($this->alpha, 'basic');

    try {
        app(TenantContext::class)->runAsPlatform(function (): void {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // expected
    }

    // A leaked flag would leave the rest of this request — and, with a pooled
    // connection, the next one — able to read every shop's billing.
    /** @var object{flag: string|null}|null $row */
    $row = DB::selectOne("select current_setting('app.platform', true) as flag");

    expect($row?->flag)->toBeIn([null, '']);

    expect(Subscription::query()->count())->toBe(0);
});
