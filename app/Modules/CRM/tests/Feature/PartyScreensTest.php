<?php

declare(strict_types=1);

use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\LoyaltyEntry;
use App\Modules\CRM\Models\LoyaltyRule;
use App\Modules\CRM\Models\Party;
use App\Modules\CRM\Models\PartyContact;
use App\Modules\CRM\Models\PartyFollowUp;
use App\Modules\CRM\Models\PartyNote;
use App\Modules\CRM\Services\LedgerService;
use App\Modules\CRM\Services\LoyaltyService;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Money;
use App\Support\Tenancy\TenantContext;
use App\Support\Timeline\TimelineRegistry;

/**
 * The Phase 4 customer screens: the party page, its balance, its timeline, and the
 * three things a shop does between transactions — notes, follow-ups and points.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = tenantUrl($this->tenant);

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();

    app(TenantProvisioner::class)->seedRoles($this->tenant);

    [$this->owner, $this->seller] = app(TenantContext::class)->runFor($this->tenant, function (): array {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        $seller = User::factory()->create();
        $seller->assignRole('Salesperson');

        return [$owner, $seller];
    });

    $this->inTenant = fn (Closure $callback): mixed => inTenantContext($this->tenant, $callback);
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * A party with an opening balance and a mobile number.
 */
function party(Tenant $tenant, string $name, int $opening = 0, ?string $mobile = null): Party
{
    /** @var Party $party */
    $party = inTenantContext($tenant, function () use ($name, $opening, $mobile): Party {
        $party = Party::factory()->create(['name' => $name, 'opening_balance' => $opening]);

        if ($mobile !== null) {
            $party->contacts()->create([
                'type' => PartyContact::TYPE_MOBILE,
                'value' => $mobile,
                'is_primary' => true,
            ]);
        }

        return $party;
    });

    return $party;
}

/* ------------------------------------------------------------------ pages -- */

it('shows a party with a balance that is the sum of the ledger, not a stored figure', function (): void {
    $customer = party($this->tenant, 'حسین کریمی', 5_000_000);

    ($this->inTenant)(function () use ($customer): void {
        $account = Account::factory()->create();

        app(LedgerService::class)->post([
            ['party_id' => $customer->id, 'debit' => 3_000_000, 'description' => 'فروش اعتباری'],
            ['account_id' => $account->id, 'credit' => 3_000_000],
        ]);
    });

    $this->actingAs($this->owner)
        ->get($this->url.'/crm/parties/'.$customer->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('CRM::Parties/Show')
            // Opening 5,000,000 plus a 3,000,000 charge.
            ->where('finance.balance.value', 8_000_000)
            ->where('finance.opening_balance.value', 5_000_000)
        );
});

it('agrees between the balance figure and the closing line of the statement', function (): void {
    // The statement and the headline number are the same rows read two ways; a page
    // where they disagree is worse than a page with neither.
    $customer = party($this->tenant, 'سمیرا احمدی', 1_000_000);

    ($this->inTenant)(function () use ($customer): void {
        $account = Account::factory()->create();
        $ledger = app(LedgerService::class);

        foreach ([2_000_000, 4_000_000] as $amount) {
            $ledger->post([
                ['party_id' => $customer->id, 'debit' => $amount],
                ['account_id' => $account->id, 'credit' => $amount],
            ]);
        }
    });

    ($this->inTenant)(function () use ($customer): void {
        $statement = app(LedgerService::class)->statement($customer);

        expect($statement['closing'])->toBe(7_000_000);
        expect($statement['closing'])->toBe(app(LedgerService::class)->partyBalance($customer));
    });

    $this->actingAs($this->owner)
        ->get($this->url.'/crm/parties/'.$customer->id)
        ->assertInertia(fn ($page) => $page
            ->where('finance.balance.value', 7_000_000)
            // Newest first, so the first row carries the closing figure.
            ->where('finance.statement.0.balance.value', 7_000_000)
        );
});

it('warns when a party is over its credit limit without blocking anything', function (): void {
    // The spec is explicit: a warning with data, not a block. Refusing a regular
    // customer over a limit nobody remembers setting loses the shop money.
    $customer = party($this->tenant, 'مشتری پرخرید', 20_000_000);

    ($this->inTenant)(fn () => $customer->update(['credit_limit' => 10_000_000]));

    $this->actingAs($this->owner)
        ->get($this->url.'/crm/parties/'.$customer->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('finance.exceeds_limit', true)
            ->where('finance.credit_limit.value', 10_000_000)
        );
});

it('withholds every financial figure from staff without crm.view_balance', function (): void {
    $customer = party($this->tenant, 'حسین کریمی', 5_000_000);

    // Null, not zero, and the limit goes with it — a credit limit is as revealing as
    // the balance it is measured against.
    $this->actingAs($this->seller)
        ->get($this->url.'/crm/parties/'.$customer->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('finance', null));
});

/* --------------------------------------------------------------- timeline -- */

it('assembles the timeline from every module that contributes', function (): void {
    $customer = party($this->tenant, 'محمدرضا کریمی');

    ($this->inTenant)(function () use ($customer): void {
        $account = Account::factory()->create();

        app(LedgerService::class)->post([
            ['party_id' => $customer->id, 'debit' => 1_000_000, 'description' => 'فروش اعتباری'],
            ['account_id' => $account->id, 'credit' => 1_000_000],
        ]);

        PartyNote::query()->create(['party_id' => $customer->id, 'body' => 'گفت هفته بعد می‌آید.']);
    });

    $this->actingAs($this->owner)
        ->get($this->url.'/crm/parties/'.$customer->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('timeline', 2)
            // Newest first, and every module answered.
            ->where('timeline_failed', [])
        );

    // Purchasing and Inventory register contributors too, even with nothing to say.
    expect(app(TimelineRegistry::class)->contributors())
        ->toContain('CRM')
        ->toContain('Purchasing')
        ->toContain('Inventory');
});

it('names a module whose timeline contribution failed rather than blanking the page', function (): void {
    $customer = party($this->tenant, 'مشتری');

    app(TimelineRegistry::class)->contribute('Repairs', function (): array {
        throw new RuntimeException('repairs is having a bad day');
    });

    // The page still renders; the gap is reported instead of being silent.
    $this->actingAs($this->owner)
        ->get($this->url.'/crm/parties/'.$customer->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('timeline_failed', ['Repairs']));
});

/* -------------------------------------------------- notes and follow-ups -- */

it('lets a salesperson write a note but not move points', function (): void {
    $customer = party($this->tenant, 'مشتری');

    // Writing a note is part of serving someone. Granting points is worth money, and
    // Salesperson holds `crm.update` — so the two cannot ride on the same permission.
    $this->actingAs($this->seller)
        ->post($this->url.'/crm/parties/'.$customer->id.'/notes', ['body' => 'تماس گرفت.'])
        ->assertRedirect();

    $this->actingAs($this->seller)
        ->post($this->url.'/crm/parties/'.$customer->id.'/loyalty', [
            'points' => 10,
            'description' => 'هدیه',
        ])
        ->assertForbidden();

    ($this->inTenant)(fn () => expect(PartyNote::query()->count())->toBe(1));
});

it('marks a follow-up done and reopens it', function (): void {
    $customer = party($this->tenant, 'مشتری');

    $this->actingAs($this->owner)
        ->post($this->url.'/crm/parties/'.$customer->id.'/follow-ups', [
            'title' => 'تماس برای گارانتی',
            'due_at' => now()->addDays(3)->toIso8601String(),
        ])
        ->assertRedirect();

    $followUp = ($this->inTenant)(fn () => PartyFollowUp::query()->firstOrFail());

    $this->actingAs($this->owner)
        ->put($this->url.'/crm/follow-ups/'.$followUp->id)
        ->assertRedirect();

    ($this->inTenant)(fn () => expect($followUp->refresh()->done_at)->not->toBeNull());

    // Toggling again reopens it — the same button, both directions.
    $this->actingAs($this->owner)->put($this->url.'/crm/follow-ups/'.$followUp->id);

    ($this->inTenant)(fn () => expect($followUp->refresh()->done_at)->toBeNull());
});

it('lists overdue follow-ups on the desk', function (): void {
    $customer = party($this->tenant, 'مشتری');

    ($this->inTenant)(fn () => PartyFollowUp::query()->create([
        'party_id' => $customer->id,
        'title' => 'تماس فراموش‌شده',
        'due_at' => now()->subWeek(),
    ]));

    $this->actingAs($this->owner)
        ->get($this->url.'/crm/follow-ups')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('CRM::FollowUps/Index')
            ->has('follow_ups.rows', 1)
            ->where('follow_ups.rows.0.is_overdue', true)
        );
});

/* ---------------------------------------------------------------- loyalty -- */

it('keeps a loyalty balance as a sum and refuses to overdraw it', function (): void {
    $customer = party($this->tenant, 'مشتری وفادار');

    ($this->inTenant)(function () use ($customer): void {
        LoyaltyRule::query()->create([
            'name' => 'قاعده پایه',
            'rial_per_point' => 1_000_000,
            'is_active' => true,
        ]);

        $loyalty = app(LoyaltyService::class);

        // 25,000,000 rial at 1,000,000 per point: 25 points, truncated not rounded.
        $loyalty->earn($customer->id, 25_500_000);

        expect($loyalty->balanceFor($customer->id))->toBe(25);

        $loyalty->redeem($customer->id, 10, 'تخفیف خرید');

        expect($loyalty->balanceFor($customer->id))->toBe(15);

        // Points are not credit: there is nothing to collect from a negative balance.
        expect(fn () => $loyalty->redeem($customer->id, 100, 'زیاده‌روی'))
            ->toThrow(RuntimeException::class);

        expect($loyalty->balanceFor($customer->id))->toBe(15);
        // The refused redemption wrote nothing.
        expect(LoyaltyEntry::query()->count())->toBe(2);
    });
});

it('does not write a zero-point entry when a spend earns nothing', function (): void {
    $customer = party($this->tenant, 'مشتری');

    ($this->inTenant)(function () use ($customer): void {
        LoyaltyRule::query()->create([
            'name' => 'قاعده پایه',
            'rial_per_point' => 1_000_000,
            'is_active' => true,
        ]);

        // Below the threshold: nothing happened, and a zero row saying so would pad
        // every customer's statement with noise.
        expect(app(LoyaltyService::class)->earn($customer->id, 500_000))->toBeNull();
        expect(LoyaltyEntry::query()->count())->toBe(0);
    });
});

/* -------------------------------------------------------------- isolation -- */

it('never shows another shop party, its balance or its follow-ups', function (): void {
    $other = Tenant::factory()->withDomain()->create();

    $foreign = party($other, 'مشتری فروشگاه دیگر', 9_000_000);

    /** @var PartyFollowUp $foreignFollowUp */
    $foreignFollowUp = inTenantContext($other, fn () => PartyFollowUp::query()->create([
        'party_id' => $foreign->id,
        'title' => 'پیگیری فروشگاه دیگر',
        'due_at' => now(),
    ]));

    party($this->tenant, 'مشتری خودمان');

    $this->actingAs($this->owner)
        ->get($this->url.'/crm/parties/'.$foreign->id)
        ->assertNotFound();

    $this->actingAs($this->owner)
        ->put($this->url.'/crm/follow-ups/'.$foreignFollowUp->id)
        ->assertNotFound();

    $this->actingAs($this->owner)
        ->get($this->url.'/crm')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('parties.rows', 1)
            ->where('parties.rows.0.name', 'مشتری خودمان')
        );
})->group('isolation');

/* ------------------------------------------------------------ create/edit -- */

it('stores an opening balance typed in toman as integer rial', function (): void {
    // Golden rule 2, on the one field where a shop types a figure straight in.
    $this->actingAs($this->owner)
        ->post($this->url.'/crm/parties', [
            'name' => 'مشتری تازه',
            'kind' => 'customer',
            'unit' => Money::UNIT_TOMAN,
            'opening_balance' => 1_200_000,
            'contacts' => [['type' => 'mobile', 'value' => '۰۹۱۲۱۱۱۲۲۳۳']],
        ])
        ->assertRedirect();

    ($this->inTenant)(function (): void {
        $party = Party::query()->where('name', 'مشتری تازه')->firstOrFail();

        expect($party->opening_balance)->toBe(12_000_000)->toBeRial();
        // Digits normalised on save, so the counter can search either way.
        expect($party->contacts()->first()?->value)->toBe('09121112233');
        // Null, not zero: nobody decided a credit limit.
        expect($party->credit_limit)->toBeNull();
    });
});

it('refuses a national id another live party already holds', function (): void {
    party($this->tenant, 'اولی');

    ($this->inTenant)(fn () => Party::query()->where('name', 'اولی')->update(['national_id' => '0012345678']));

    $this->actingAs($this->owner)
        ->post($this->url.'/crm/parties', [
            'name' => 'دومی',
            'kind' => 'customer',
            'unit' => Money::UNIT_RIAL,
            'national_id' => '0012345678',
            'contacts' => [],
        ])
        ->assertSessionHasErrors('national_id');
});
