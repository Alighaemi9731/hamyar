<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Domain;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;

/**
 * A shop on its first morning, before its first row.
 *
 * The smoke suite walks a populated shop, which is the one state an empty-state branch
 * never renders in. So the screens a new shop meets first — the repair board with no
 * ticket, the collection desk with no plan, the storefront with no link, the import
 * pages before a file — had no witness, and «قسطی در انتظار نیست.» in a bare paragraph
 * shipped on three of them. This walks those screens on a tenant that has done nothing
 * yet, and asserts each state says what is missing and offers the next action (the
 * copy rule in `empty-state.tsx`: a noun phrase, then a door).
 *
 * The fixture is the smoke suite's without `seedShopData()`: the same `127.0.0.1` domain
 * trick, for the same reason (see `SmokeTest.php`).
 */
pest()->group('browser');

beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->create();

    Domain::query()->create([
        'tenant_id' => $this->tenant->getKey(),
        'hostname' => '127.0.0.1',
        'is_primary' => true,
    ]);

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    $this->owner = app(TenantContext::class)->runFor($this->tenant, function (): User {
        $user = User::factory()->create(['name' => 'مالک']);
        $user->assignRole('Owner');

        return $user;
    });
});

it('names what is missing and offers the next action on a shop with nothing in it', function (string $path, string $title, string $action): void {
    $this->actingAs($this->owner);

    visit($path)->inLightMode()->on()->desktop()
        ->assertNoJavascriptErrors()
        ->assertSee($title)
        ->assertSee($action);
})->with([
    'repair board' => ['/repairs/board', 'دستگاهی روی میز تعمیر نیست', 'پذیرش دستگاه'],
    'collection desk' => ['/installments/collections', 'قسطی در انتظار وصول نیست', 'فروش اقساطی جدید'],
    'storefront links' => ['/storefront', 'هنوز لینکی ساخته نشده است', 'با فرم بالا'],
    'customer import' => ['/crm/import', 'فایل را اینجا رها کنید یا انتخاب کنید', 'CSV'],
    'product import' => ['/catalog/import', 'فایل را اینجا رها کنید یا انتخاب کنید', 'دریافت قالب خالی'],
]);

it('opens a new shop on the first morning\'s checklist, with the first step lit', function (): void {
    $this->actingAs($this->owner);

    visit('/dashboard')->inLightMode()->on()->desktop()
        ->assertNoJavascriptErrors()
        ->assertSee('راه‌اندازی فروشگاه')
        ->assertSee('اولین کالا را ثبت کنید')
        ->assertSee('شروع');
});

it('tells a technician with no assigned work how to see everyone\'s', function (): void {
    $this->actingAs($this->owner);

    visit('/repairs/board?mine=1')->inLightMode()->on()->desktop()
        ->assertNoJavascriptErrors()
        ->assertSee('کاری به شما سپرده نشده است')
        ->assertSee('همهٔ کارها');
});
