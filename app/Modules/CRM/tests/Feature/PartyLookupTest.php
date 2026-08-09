<?php

declare(strict_types=1);

use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\Party;
use App\Modules\CRM\Models\PartyContact;
use App\Modules\CRM\Services\LedgerService;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;

/**
 * The lookup behind `<PartyPicker/>`.
 *
 * What is worth testing here is not "does it filter" but the three things that would
 * quietly break the counter: a Persian-typed phone number finding nobody, a balance
 * reaching a Salesperson who may not see one, and another shop's customers appearing
 * in the list.
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
});

afterEach(fn () => app(TenantContext::class)->forget());

function makeParty(Tenant $tenant, string $name, ?string $mobile = null, int $opening = 0): Party
{
    return app(TenantContext::class)->runFor($tenant, function () use ($name, $mobile, $opening): Party {
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
}

/* ------------------------------------------------------------------ search -- */

it('finds a party by name', function (): void {
    makeParty($this->tenant, 'محمدرضا کریمی');
    makeParty($this->tenant, 'سمیرا احمدی');

    $this->actingAs($this->owner)
        ->getJson($this->url.'/crm/parties/search?q=کریمی')
        ->assertOk()
        ->assertJsonCount(1, 'results')
        ->assertJsonPath('results.0.name', 'محمدرضا کریمی');
});

it('finds a party by a phone number typed with Persian digits', function (): void {
    // The number is stored normalised. If the query is not normalised too, the shop
    // types the number the way their keyboard produces it and finds nobody.
    makeParty($this->tenant, 'سمیرا احمدی', '09121112233');

    $this->actingAs($this->owner)
        ->getJson($this->url.'/crm/parties/search?q=۰۹۱۲۱۱۱۲۲۳۳')
        ->assertOk()
        ->assertJsonCount(1, 'results')
        ->assertJsonPath('results.0.mobile', '09121112233');
});

it('leaves inactive parties out of the list', function (): void {
    $party = makeParty($this->tenant, 'مشتری قدیمی');

    app(TenantContext::class)->runFor($this->tenant, fn () => $party->update(['is_active' => false]));

    $this->actingAs($this->owner)
        ->getJson($this->url.'/crm/parties/search?q=قدیمی')
        ->assertOk()
        ->assertJsonCount(0, 'results');
});

/* ----------------------------------------------------------------- balance -- */

it('reports a balance that includes the opening figure and every entry', function (): void {
    $party = makeParty($this->tenant, 'حسین کریمی', null, 5_000_000);

    app(TenantContext::class)->runFor($this->tenant, function () use ($party): void {
        $account = Account::factory()->create();

        // He bought on credit: the shop is owed more than the opening figure said.
        app(LedgerService::class)->post([
            ['party_id' => $party->id, 'debit' => 3_000_000],
            ['account_id' => $account->id, 'credit' => 3_000_000],
        ]);
    });

    $this->actingAs($this->owner)
        ->getJson($this->url.'/crm/parties/search?q=حسین')
        ->assertOk()
        ->assertJsonPath('results.0.balance.value', 8_000_000);
});

it('withholds the balance from staff without crm.view_balance', function (): void {
    makeParty($this->tenant, 'حسین کریمی', null, 5_000_000);

    // Null, not zero: the figure never reaches a browser that may not show it, and a
    // zero would be a lie the UI could not distinguish from a settled account.
    $this->actingAs($this->seller)
        ->getJson($this->url.'/crm/parties/search?q=حسین')
        ->assertOk()
        ->assertJsonPath('results.0.balance', null);
});

/* ----------------------------------------------------------- authorization -- */

it('refuses the lookup to a user without crm.view', function (): void {
    $stranger = app(TenantContext::class)->runFor(
        $this->tenant,
        fn () => User::factory()->create()
    );

    $this->actingAs($stranger)
        ->getJson($this->url.'/crm/parties/search')
        ->assertForbidden();
});

it('refuses the lookup to a shop with no usable subscription', function (): void {
    // Golden rule 7: hiding the nav item is convenience, this is the enforcement. The
    // gating layer fails closed — an unsubscribed shop gets nothing, not the core
    // modules for free.
    $lapsed = Tenant::factory()->withDomain()->create();

    app(TenantProvisioner::class)->seedRoles($lapsed);
    app(SubscriptionResolver::class)->forget();

    $owner = app(TenantContext::class)->runFor($lapsed, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Owner');

        return $user;
    });

    $this->actingAs($owner)
        ->getJson(tenantUrl($lapsed).'/crm/parties/search')
        ->assertForbidden();
});

/* --------------------------------------------------------------- isolation -- */

it('never returns another shop customers', function (): void {
    $other = Tenant::factory()->withDomain()->create();

    makeParty($other, 'مشتری فروشگاه دیگر');
    makeParty($this->tenant, 'مشتری خودمان');

    $this->actingAs($this->owner)
        ->getJson($this->url.'/crm/parties/search?q=مشتری')
        ->assertOk()
        ->assertJsonCount(1, 'results')
        ->assertJsonPath('results.0.name', 'مشتری خودمان');
})->group('isolation');
