<?php

declare(strict_types=1);

use App\Modules\Cheques\Models\Cheque;
use App\Modules\CRM\Models\Party;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Branch;
use App\Modules\Inventory\Services\BranchAccess;
use App\Modules\Inventory\Services\BranchContext;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Branch context: the view lens, and the access floor underneath it.
 *
 * ## What the Phase 10.1 audit actually found
 *
 * `branch_user` had existed since Phase 2 and `BranchAccess` read it correctly. Three
 * modules enforced it. **Five did not, the reports did not, and nothing anywhere could
 * write to the table** — there was no branch screen, no assignment control and no switcher,
 * so no shop could reach any of it.
 *
 * That combination is why the gap was invisible: with no way to assign anybody to a branch,
 * every user was unrestricted, every query returned everything, and every screen looked
 * right. The bug was latent until the moment the feature became usable — which is the same
 * shape as a guard that silently passes.
 *
 * So the tests below are written against the state the audit created: a shop with two
 * branches and a user pinned to one of them.
 *
 * ## The two questions are tested apart, then together
 *
 * `BranchAccess` is a **permission** and `BranchContext` is a **view**, and the
 * privilege-escalation case sits exactly where they meet: a restricted user selecting «همه
 * شعب» must see their branches, not every branch. That is the assertion this file exists
 * for.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    /** @var array{User, User, Branch, Branch} $fixtures */
    $fixtures = app(TenantContext::class)->runFor($this->tenant, function (): array {
        $owner = User::factory()->create(['name' => 'مالک']);
        $owner->assignRole('Owner');

        $northOnly = User::factory()->create(['name' => 'صندوق‌دار شمال']);
        $northOnly->assignRole('Cashier');

        $north = Branch::query()->first() ?? Branch::factory()->create();
        $north->forceFill(['name' => 'شعبه شمال', 'code' => 'N1', 'is_default' => true])->save();

        $south = Branch::factory()->create([
            'name' => 'شعبه جنوب',
            'code' => 'S1',
            'is_default' => false,
            'is_active' => true,
        ]);

        // The cashier works at the north branch only. The owner is assigned to nothing,
        // which is how this product spells "every branch".
        $north->users()->sync([$northOnly->getKey()]);

        $party = Party::factory()->create(['name' => 'مشتری', 'kind' => 'customer']);

        $cheque = function (Branch $branch, int $amount, string $serial): void {
            Cheque::query()->create([
                'branch_id' => $branch->getKey(),
                'direction' => 'received',
                'status' => 'in_hand',
                'party_id' => Party::query()->firstOrFail()->getKey(),
                'amount' => $amount,
                'bank_name' => 'ملت',
                'serial' => $serial,
                'due_date' => CarbonImmutable::now()->addDays(10)->toDateString(),
            ]);
        };

        $cheque($north, 12_340_000, 'N-0001');
        $cheque($south, 45_670_000, 'S-0001');

        // A cheque belonging to no branch — the shop's own paper. It must survive a
        // per-branch view, or the two branches' lists stop adding up to the book.
        Cheque::query()->create([
            'branch_id' => null,
            'direction' => 'received',
            'status' => 'in_hand',
            'party_id' => $party->getKey(),
            'amount' => 8_910_000,
            'bank_name' => 'صادرات',
            'serial' => 'X-0001',
            'due_date' => CarbonImmutable::now()->addDays(10)->toDateString(),
        ]);

        return [$owner, $northOnly, $north, $south];
    });

    [$this->owner, $this->northOnly, $this->north, $this->south] = $fixtures;

    app(BranchAccess::class)->forget();
});

afterEach(function (): void {
    app(BranchAccess::class)->forget();
    app(TenantContext::class)->forget();
});

/* ------------------------------------------------------- the access floor -- */

it('never lets a restricted user widen their reach by choosing all branches', function (): void {
    /*
    | The assertion this file exists for.
    |
    | The obvious implementation — one "current branch" that the switcher writes and every
    | query reads — makes «همه شعب» a privilege-escalation button: the filter is dropped and
    | the restricted user reads the branch they were kept out of. `BranchContext::apply()`
    | composes the floor first and does not consult the session for it.
    */
    $this->actingAs($this->northOnly);

    /*
    | Inside the tenant context, and that is not ceremony. `branch_user` is under RLS, so a
    | service called straight from the test process — where `app.tenant_id` is unset —
    | reads ZERO assignment rows, and `BranchAccess` correctly reads zero rows as
    | "unrestricted". The first version of this test failed claiming the restriction did
    | not exist; the restriction was fine and the harness was outside the shop.
    */
    inTenantContext($this->tenant, function (): void {
        $context = app(BranchContext::class);

        // Consolidated is not even offered — they have one branch — but ask for it anyway,
        // which is what a hand-edited request would do.
        expect($context->set(null, $this->northOnly))->toBeFalse();

        expect($context->scopeIds($this->northOnly))->toBe([(int) $this->north->getKey()]);
    });
});

it('consolidates only the branches a restricted user is allowed', function (): void {
    /*
    | The middle row of `scopeIds()`'s table, and the case a `?int $branchId` parameter
    | could not express — which is why the report controllers passed nothing at all and a
    | regional manager read the whole shop.
    */
    $third = app(TenantContext::class)->runFor($this->tenant, fn (): Branch => Branch::factory()->create([
        'name' => 'شعبه سوم', 'code' => 'T1', 'is_default' => false, 'is_active' => true,
    ]));

    app(TenantContext::class)->runFor($this->tenant, function () use ($third): void {
        // A regional manager over two of the three branches.
        $this->north->users()->syncWithoutDetaching([$this->northOnly->getKey()]);
        $third->users()->syncWithoutDetaching([$this->northOnly->getKey()]);
    });

    app(BranchAccess::class)->forget();

    $this->actingAs($this->northOnly);

    inTenantContext($this->tenant, function () use ($third): void {
        $context = app(BranchContext::class);

        expect($context->canConsolidate($this->northOnly))->toBeTrue();
        expect($context->set(null, $this->northOnly))->toBeTrue();

        /** @var list<int> $scope */
        $scope = $context->scopeIds($this->northOnly);

        sort($scope);
        $expected = [idOf($this->north), idOf($third)];
        sort($expected);

        // Two of three — not null, which would mean "everything".
        expect($scope)->toBe($expected);
        expect($scope)->not->toContain((int) $this->south->getKey());
    });
});

it('refuses to pin a branch the user may not see', function (): void {
    $this->actingAs($this->northOnly);

    inTenantContext($this->tenant, function (): void {
        expect(app(BranchContext::class)->set((int) $this->south->getKey(), $this->northOnly))->toBeFalse();
    });

    // And the endpoint says so rather than silently doing nothing.
    $this->actingAs($this->northOnly)
        ->from($this->url.'/cheques')
        ->post($this->url.'/branch/switch', ['branch_id' => $this->south->getKey()])
        ->assertRedirect($this->url.'/cheques')
        ->assertSessionHas('error');
});

it('re-validates a pinned branch that stopped being the user’s', function (): void {
    /*
    | An owner can narrow somebody's assignments while they are still logged in. The branch
    | in their session was a permission an hour ago and is not one now, so it is checked on
    | every read rather than trusted because it is in the session.
    */
    $this->actingAs($this->owner);

    inTenantContext($this->tenant, function (): void {
        $context = app(BranchContext::class);

        expect($context->set((int) $this->south->getKey(), $this->owner))->toBeTrue();
        expect($context->current($this->owner))->toBe((int) $this->south->getKey());

        // The owner is now pinned to the north branch only.
        $this->north->users()->syncWithoutDetaching([$this->owner->getKey()]);

        app(BranchAccess::class)->forget();

        // The session still says "south"; the answer is no longer south.
        expect($context->current($this->owner))->toBe((int) $this->north->getKey());
    });
});

/* ------------------------------------------------- the modules that ignored it -- */

it('filters a module that had no branch filter at all before the audit', function (): void {
    /*
    | Cheques is one of the five. Before 10.1 this endpoint returned every branch's paper to
    | everybody, and the test that would have caught it did not exist because no shop could
    | create a second branch to notice with.
    */
    $this->actingAs($this->northOnly)
        ->get($this->url.'/cheques?direction=received')
        ->assertOk()
        ->assertInertia(function ($page): void {
            $serials = array_column(paginatedOf($page, 'cheques'), 'serial');

            expect($serials)->toContain('N-0001');
            expect($serials)->not->toContain('S-0001');

            // The unassigned cheque survives: it is the shop's paper, not nobody's, and
            // dropping it would make the branches stop summing to the book.
            expect($serials)->toContain('X-0001');
        });
});

it('shows the owner every branch until they choose one', function (): void {
    $this->actingAs($this->owner)
        ->get($this->url.'/cheques?direction=received')
        ->assertOk()
        ->assertInertia(function ($page): void {
            $serials = array_column(paginatedOf($page, 'cheques'), 'serial');

            expect($serials)->toContain('N-0001')
                ->and($serials)->toContain('S-0001')
                ->and($serials)->toContain('X-0001');
        });

    $this->actingAs($this->owner)
        ->post($this->url.'/branch/switch', ['branch_id' => $this->south->getKey()])
        ->assertRedirect();

    $this->actingAs($this->owner)
        ->get($this->url.'/cheques?direction=received')
        ->assertOk()
        ->assertInertia(function ($page): void {
            $serials = array_column(paginatedOf($page, 'cheques'), 'serial');

            expect($serials)->toContain('S-0001')
                ->and($serials)->not->toContain('N-0001');
        });
});

/* ---------------------------------------------------------------- the switcher -- */

it('offers no switcher to a shop with one branch a user can see', function (): void {
    $this->actingAs($this->northOnly)
        ->get($this->url.'/cheques')
        ->assertOk()
        ->assertInertia(function ($page): void {
            /** @var array<string, mixed> $branch */
            $branch = propsOf($page)['branch'];

            // One option and no consolidated choice: the component renders nothing, which
            // is the right answer for almost every shop in this market.
            expect($branch['options'])->toHaveCount(1)
                ->and($branch['can_consolidate'])->toBeFalse();
        });
});

it('offers both branches to the owner', function (): void {
    $this->actingAs($this->owner)
        ->get($this->url.'/cheques')
        ->assertOk()
        ->assertInertia(function ($page): void {
            /** @var array<string, mixed> $branch */
            $branch = propsOf($page)['branch'];

            expect($branch['options'])->toHaveCount(2)
                ->and($branch['can_consolidate'])->toBeTrue()
                ->and($branch['current'])->toBeNull();
        });
});

/* ------------------------------------------------------------------ management -- */

it('creates a branch and moves the default in one transaction', function (): void {
    $this->actingAs($this->owner)
        ->post($this->url.'/branches', [
            'name' => 'شعبه شرق',
            'code' => 'e2',
            'is_active' => true,
            'is_default' => true,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    app(TenantContext::class)->runFor($this->tenant, function (): void {
        // Codes are stored upper-cased: they lead a document number, and «e2» and «E2»
        // reading as two branches on two printed invoices is the confusion the unique
        // index exists to prevent.
        $created = Branch::query()->where('code', 'E2')->firstOrFail();

        expect($created->is_default)->toBeTrue();

        // Exactly one default, always — a partial unique index says so, and the move has
        // to clear the old flag and set the new one together.
        expect(Branch::query()->where('is_default', true)->count())->toBe(1);
    });
});

it('rejects a duplicate branch code with a sentence rather than a 500', function (): void {
    $this->actingAs($this->owner)
        ->post($this->url.'/branches', ['name' => 'تکراری', 'code' => 'S1'])
        ->assertSessionHasErrors('code');
});

it('assigns staff to a branch, and unassigning everybody is a real instruction', function (): void {
    $this->actingAs($this->owner)
        ->put($this->url.'/branches/'.$this->south->getKey().'/users', [
            'user_ids' => [$this->northOnly->getKey()],
        ])
        ->assertSessionHasNoErrors();

    app(BranchAccess::class)->forget();

    inTenantContext($this->tenant, function (): void {
        expect(app(BranchAccess::class)->canUse($this->northOnly, (int) $this->south->getKey()))->toBeTrue();
    });

    app(BranchAccess::class)->forget();

    /*
    | Now unassign everybody by posting NO `user_ids` key at all — which is what an unticked
    | checkbox group sends. `present` or `required` on that key would reject the ordinary
    | case of clearing a branch's staff list, and only a test that omits the key catches it.
    */
    $this->actingAs($this->owner)
        ->put($this->url.'/branches/'.$this->south->getKey().'/users', [])
        ->assertSessionHasNoErrors();

    app(BranchAccess::class)->forget();

    inTenantContext($this->tenant, function (): void {
        expect(app(BranchAccess::class)->allowedFor($this->northOnly))
            ->toBe([(int) $this->north->getKey()]);
    });
});

it('refuses branch management to somebody without the settings permission', function (): void {
    $this->actingAs($this->northOnly)
        ->get($this->url.'/branches')
        ->assertForbidden();

    $this->actingAs($this->northOnly)
        ->post($this->url.'/branches', ['name' => 'قاچاقی', 'code' => 'X9'])
        ->assertForbidden();
});

/* ------------------------------------------------------------------- isolation -- */

it('never lists another shop’s branches in the switcher', function (): void {
    $other = Tenant::factory()->withDomain()->create();
    subscribe($other, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($other);

    $neighbour = app(TenantContext::class)->runFor($other, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Owner');

        return $user;
    });

    // The positive half: this shop really does have two branches.
    $this->actingAs($this->owner)
        ->get($this->url.'/cheques')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->count('branch.options', 2)->etc());

    $this->actingAs($neighbour)
        ->get(appUrl().'/branches')
        ->assertOk()
        ->assertInertia(function ($page): void {
            $names = array_column(rowsOf($page, 'branches'), 'name');

            expect($names)->not->toContain('شعبه شمال')
                ->and($names)->not->toContain('شعبه جنوب');
        });
})->group('isolation');
