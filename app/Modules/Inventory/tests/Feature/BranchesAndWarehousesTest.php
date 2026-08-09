<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Branch;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\BranchAccess;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Counters\Counter;
use App\Support\Counters\CounterService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * `getKey()` is typed `mixed` on Eloquent, and these ids are passed to `int` parameters
 * all over this file. One narrowing helper beats a cast on every line.
 */
function branchId(Branch $branch): int
{
    /** @var int $id */
    $id = $branch->getKey();

    return $id;
}

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->access = app(BranchAccess::class);
    $this->access->forget();
});

afterEach(fn () => app(TenantContext::class)->forget());

/* -------------------------------------------------------------- defaults -- */

it('gives a newly provisioned shop one branch and one warehouse', function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    // Most shops are a single storefront and will never configure this, so it has to
    // exist without anyone touching it.
    $tenant = app(TenantProvisioner::class)->provision([
        'name' => 'موبایل ایرانیان',
        'subdomain' => 'iranian-mobile',
        'owner_name' => 'رضا',
        'owner_mobile' => '09121112233',
        'owner_email' => null,
        'password' => 'secret-secret-1',
    ]);

    app(TenantContext::class)->runFor($tenant, function (): void {
        $branch = Branch::query()->firstOrFail();
        $warehouse = Warehouse::query()->firstOrFail();

        expect($branch->is_default)->toBeTrue();
        expect($branch->code)->toBe('MAIN');
        expect($warehouse->branch_id)->toBe($branch->getKey());
        expect($warehouse->is_sellable)->toBeTrue();
        expect($warehouse->is_default)->toBeTrue();
    });
});

it('rolls the whole signup back if the default location cannot be created', function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    // A shop that exists but cannot receive stock is worse than a failed signup, so the
    // listener runs inside the provisioning transaction. Forcing it to fail proves the
    // rollback rather than assuming it.
    Event::listen(App\Modules\Platform\Events\TenantProvisioned::class, function (): void {
        throw new RuntimeException('boom');
    });

    $before = Tenant::query()->count();

    expect(fn () => app(TenantProvisioner::class)->provision([
        'name' => 'ناموفق',
        'subdomain' => 'failed-shop',
        'owner_name' => 'علی',
        'owner_mobile' => '09121110000',
        'owner_email' => null,
        'password' => 'secret-secret-1',
    ]))->toThrow(RuntimeException::class);

    expect(Tenant::query()->count())->toBe($before);
});

/* ----------------------------------------------------------- one default -- */

it('refuses a second default branch', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        Branch::factory()->default()->create();

        // Enforced by a partial unique index, not by application etiquette — two
        // defaults would make "which branch is this invoice for" ambiguous.
        expect(fn () => DB::transaction(fn () => Branch::factory()->default()->create(['code' => 'TWO'])))
            ->toThrow(QueryException::class);
    });
});

it('still allows many non-default branches', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        Branch::factory()->count(3)->create();

        // A plain unique on (tenant_id, is_default) would have forbidden this, which is
        // why the index is partial.
        expect(Branch::query()->count())->toBe(3);
    });
});

it('lets two different tenants each have a default branch', function (): void {
    pest()->group('isolation');

    $other = Tenant::factory()->withDomain()->create();

    app(TenantContext::class)->runFor($this->tenant, fn () => Branch::factory()->default()->create());
    app(TenantContext::class)->runFor($other, fn () => Branch::factory()->default()->create());

    app(TenantContext::class)->runFor(
        $this->tenant,
        fn () => expect(Branch::query()->count())->toBe(1)
    );
});

/* --------------------------------------------------------- branch access -- */

it('treats no assignment as access to every branch', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $first = Branch::factory()->create();
        $second = Branch::factory()->create();
        $user = User::factory()->create();

        // The opposite of how permissions usually work, and deliberate: a single-branch
        // shop must not have to configure anything.
        expect($this->access->allowedFor($user))->toBeNull();
        expect($this->access->canUse($user, branchId($first)))->toBeTrue();
        expect($this->access->canUse($user, branchId($second)))->toBeTrue();
    });
});

it('restricts a user once branches are assigned', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $karaj = Branch::factory()->create();
        $tehran = Branch::factory()->create();

        $user = User::factory()->create();
        $karaj->users()->attach($user);

        $this->access->forget();

        expect($this->access->allowedFor($user))->toBe([branchId($karaj)]);
        expect($this->access->canUse($user, branchId($tehran)))->toBeFalse();
    });
});

it('constrains a query to the assigned branches', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $karaj = Branch::factory()->create();
        Branch::factory()->create();

        $user = User::factory()->create();
        $karaj->users()->attach($user);

        $this->access->forget();

        $visible = $this->access->constrain(Branch::query(), $user, 'id')->pluck('id')->all();

        expect($visible)->toBe([$karaj->getKey()]);
    });
});

it('prefers an assigned branch over the shop default', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        Branch::factory()->default()->create();
        $karaj = Branch::factory()->create();

        $user = User::factory()->create();
        $karaj->users()->attach($user);

        $this->access->forget();

        // Preselecting the shop default for someone who cannot use it would put an
        // invalid branch on every new invoice they open.
        expect($this->access->defaultFor($user)?->getKey())->toBe($karaj->getKey());
    });
});

/* -------------------------------------------------------------- counters -- */

it('numbers documents separately per branch', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        /** @var int $tenantId */
        $tenantId = $this->tenant->getKey();

        $tehran = Branch::factory()->create();
        $karaj = Branch::factory()->create();

        $counters = app(CounterService::class);

        DB::transaction(function () use ($counters, $tenantId, $tehran, $karaj): void {
            expect($counters->next($tenantId, Counter::SALES_INVOICE, branchId($tehran)))->toBe(1);
            expect($counters->next($tenantId, Counter::SALES_INVOICE, branchId($tehran)))->toBe(2);
            // Two shopfronts sharing one sequence interleaves their invoice numbers,
            // which Iranian tax practice does not expect (docs/specs/settings.md).
            expect($counters->next($tenantId, Counter::SALES_INVOICE, branchId($karaj)))->toBe(1);
        });
    });
});

it('keeps tenant-level numbering separate from any branch', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        /** @var int $tenantId */
        $tenantId = $this->tenant->getKey();

        $branch = Branch::factory()->create();
        $counters = app(CounterService::class);

        DB::transaction(function () use ($counters, $tenantId, $branch): void {
            // A subscription invoice belongs to the shop, not a shopfront, so it uses
            // the null-branch sequence and must not consume a branch number.
            expect($counters->next($tenantId, Counter::SUBSCRIPTION_INVOICE))->toBe(1);
            expect($counters->next($tenantId, Counter::SUBSCRIPTION_INVOICE, branchId($branch)))->toBe(1);
        });
    });
});

/* ------------------------------------------------------------- isolation -- */

it('does not leak one shop branches into another', function (): void {
    pest()->group('isolation');

    $other = Tenant::factory()->withDomain()->create();

    app(TenantContext::class)->runFor($this->tenant, fn () => Branch::factory()->count(2)->create());
    app(TenantContext::class)->runFor($other, fn () => Branch::factory()->create());

    app(TenantContext::class)->runFor(
        $other,
        fn () => expect(Branch::query()->count())->toBe(1)
    );
});

it('does not leak warehouses across tenants', function (): void {
    pest()->group('isolation');

    $other = Tenant::factory()->withDomain()->create();

    app(TenantContext::class)->runFor($this->tenant, fn () => Warehouse::factory()->count(2)->create());
    app(TenantContext::class)->runFor($other, fn () => Warehouse::factory()->create());

    app(TenantContext::class)->runFor(
        $other,
        fn () => expect(Warehouse::query()->count())->toBe(1)
    );
});
