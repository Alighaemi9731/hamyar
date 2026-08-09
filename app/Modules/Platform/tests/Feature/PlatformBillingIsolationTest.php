<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Module;
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

/**
 * The child tables were the Gate 2 item-0 gap: they had a foreign key to a protected
 * parent and nothing of their own. These assert they now stand on their own, because
 * Phase 2.4 will query them directly by id.
 */
dataset('billing child tables', [
    'add-ons' => ['subscription_addons', 'subscription_id'],
    'payment attempts' => ['payment_attempts', 'subscription_invoice_id'],
]);

it('denies a cross-tenant raw insert into each billing child table', function (string $table, string $parentKey): void {
    $mine = subscribe($this->alpha, 'basic');
    $theirs = subscribe($this->beta, 'basic');

    // Whatever the parent column means, point it at a row that exists so the ONLY
    // reason this can fail is the tenant policy.
    $parentId = $table === 'subscription_addons'
        ? $theirs->getKey()
        : app(TenantContext::class)->runAsPlatform(fn (): int => (int) DB::table('subscription_invoices')->insertGetId([
            'tenant_id' => $this->beta->getKey(),
            'number' => 'TEST-1',
            'subtotal' => 1000, 'total' => 1000,
            'lines' => '[]', 'created_at' => now(), 'updated_at' => now(),
        ]));

    $row = $table === 'subscription_addons'
        ? ['module_id' => Module::query()->value('id'), 'price' => 0, 'starts_at' => now()]
        : ['gateway' => 'zarinpal', 'amount' => 1000, 'status' => 'initiated', 'payload' => '{}'];

    app(TenantContext::class)->runFor($this->alpha, function () use ($table, $parentKey, $parentId, $row): void {
        expect(fn () => DB::transaction(fn () => DB::table($table)->insert([
            ...$row,
            $parentKey => $parentId,
            // Tenant alpha, forging a row onto tenant beta's billing.
            'tenant_id' => $this->beta->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ])))->toThrow(QueryException::class, 'violates row-level security policy');
    });

    unset($mine);
})->with('billing child tables');

it('hides one shop billing children from another', function (string $table): void {
    $subscription = subscribe($this->beta, 'pro');

    app(TenantContext::class)->runAsPlatform(function () use ($table, $subscription): void {
        $row = $table === 'subscription_addons'
            ? ['subscription_id' => $subscription->getKey(), 'module_id' => Module::query()->value('id'), 'price' => 0, 'starts_at' => now()]
            : ['subscription_invoice_id' => DB::table('subscription_invoices')->insertGetId([
                'tenant_id' => $this->beta->getKey(), 'number' => 'TEST-2',
                'subtotal' => 1000, 'total' => 1000, 'lines' => '[]',
                'created_at' => now(), 'updated_at' => now(),
            ]), 'gateway' => 'zarinpal', 'amount' => 1000, 'status' => 'initiated', 'payload' => '{}'];

        DB::table($table)->insert([...$row, 'tenant_id' => $this->beta->getKey(), 'created_at' => now(), 'updated_at' => now()]);
    });

    $seen = app(TenantContext::class)->runFor(
        $this->alpha,
        fn (): int => DB::table($table)->count()
    );

    expect($seen)->toBe(0);
})->with([['subscription_addons'], ['payment_attempts']]);
