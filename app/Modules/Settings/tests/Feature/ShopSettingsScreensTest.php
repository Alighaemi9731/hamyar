<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Enums\AutomationKey;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Settings\Services\TenantShopSettings;
use App\Support\Settings\MessagingSettings;
use App\Support\Settings\PrintSettings;
use App\Support\Settings\ShopSettings;
use App\Support\Tenancy\TenantContext;

/**
 * The two shop-settings screens, and the defaults they must not break.
 *
 * `TenantShopSettings` has read a dozen preferences since Phase 1 and nothing wrote one:
 * every value on these screens could previously be changed only by editing a JSON column
 * by hand. Two of its readers are deliberately strict, and a write path is exactly where
 * that strictness gets quietly undone — so most of what is asserted below is about the
 * *type* of what lands in the document, not only its value.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    // `refresh()` for the reason SettingsHubTest records: a model straight out of
    // `create()` is missing its untouched nullable columns, and reading one throws
    // outside production.
    [$this->owner, $this->cashier] = app(TenantContext::class)->runFor($this->tenant, function (): array {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');
        $owner->refresh();

        $cashier = User::factory()->create();
        $cashier->assignRole('Cashier');
        $cashier->refresh();

        return [$owner, $cashier];
    });
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * What the shop's settings document holds under one namespace, read back from the row.
 *
 * Takes `mixed` and refuses anything else rather than declaring `Tenant`: Pest binds
 * `$this` at runtime, so `$this->tenant` is `mixed` to static analysis, and a silent
 * fallback would let an assertion pass against an empty array it invented.
 *
 * @return array<string, mixed>
 */
function shopSettingSection(mixed $tenant, string $namespace): array
{
    if (! $tenant instanceof Tenant) {
        throw new InvalidArgumentException('shopSettingSection() needs a Tenant.');
    }

    $section = $tenant->fresh()?->setting($namespace);

    return is_array($section) ? $section : [];
}

// ---------------------------------------------------------------- چاپ ----

it('opens the print screen with the QR already on for a shop that has never saved', function (): void {
    /*
    | The reader's documented default: `show_qr` missing means ON, because a missing
    | setting resolving to "no QR" would silently drop the feature for every existing
    | shop. The screen therefore has to render the RESOLVED value, not the raw column —
    | an unticked box here would be the product lying about its own behaviour on the one
    | screen a shopkeeper goes to check it.
    */
    expect($this->tenant->setting('print'))->toBeNull();

    $this->actingAs($this->owner)
        ->get(appUrl('/settings/print'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Settings::Settings/Print')
            ->where('print.show_qr', true)
            ->where('print.logo_url', null)
            ->where('print.footer_terms', null)
            ->where('can_update', true)
        );
});

it('saves what a customer ends up holding', function (): void {
    $this->actingAs($this->owner)
        ->from(appUrl('/settings/print'))
        ->put(appUrl('/settings/print'), [
            'logo_url' => 'https://example.test/logo.png',
            'footer_terms' => 'گارانتی دستگاه شش ماه است و شامل آسیب فیزیکی نمی‌شود.',
            'show_qr' => false,
        ])
        ->assertRedirect(appUrl('/settings/print'))
        ->assertSessionHasNoErrors();

    $print = shopSettingSection($this->tenant, 'print');

    expect($print['logo_url'])->toBe('https://example.test/logo.png')
        ->and($print['footer_terms'])->toBe('گارانتی دستگاه شش ماه است و شامل آسیب فیزیکی نمی‌شود.')
        // A REAL boolean. `0`, `'0'` and `'false'` are all `!== false`, so any of them
        // would leave the QR switched on while the screen showed it off.
        ->and($print['show_qr'])->toBeFalse();

    /** @var PrintSettings $resolved */
    $resolved = app(TenantContext::class)->runFor(
        $this->tenant,
        fn (): PrintSettings => app(ShopSettings::class)->print(),
    );

    expect($resolved->showQr)->toBeFalse()
        ->and($resolved->logoUrl)->toBe('https://example.test/logo.png');
});

it('stores a real true when the QR is left on, so nothing has to fall back to the default', function (): void {
    $this->actingAs($this->owner)
        ->from(appUrl('/settings/print'))
        ->put(appUrl('/settings/print'), [
            'logo_url' => '',
            'footer_terms' => '',
            'show_qr' => true,
        ])
        ->assertSessionHasNoErrors();

    $print = shopSettingSection($this->tenant, 'print');

    expect($print['show_qr'])->toBeTrue()
        // An emptied field stores null rather than an empty string: `PrintSettings::$logoUrl`
        // is `?string` and a zero-length URL would ride along in every invoice snapshot.
        ->and($print['logo_url'])->toBeNull()
        ->and($print['footer_terms'])->toBeNull();
});

it('refuses a save that forgot to mention the QR at all', function (): void {
    /*
    | The reason `show_qr` is `required` rather than `boolean`. Without it, a request
    | that simply omitted the key would read as `false` and turn a shop's QR off without
    | anybody asking — the same silent-change class as the default it protects.
    */
    $this->actingAs($this->owner)
        ->from(appUrl('/settings/print'))
        ->put(appUrl('/settings/print'), ['logo_url' => '', 'footer_terms' => ''])
        ->assertSessionHasErrors('show_qr');

    expect(shopSettingSection($this->tenant, 'print'))->toBe([]);
});

it('refuses a logo that is not a web address, and says so', function (): void {
    $response = $this->actingAs($this->owner)
        ->from(appUrl('/settings/print'))
        ->put(appUrl('/settings/print'), [
            'logo_url' => 'نشانی اشتباه',
            'footer_terms' => '',
            'show_qr' => true,
        ]);

    $response->assertRedirect(appUrl('/settings/print'))
        ->assertSessionHasErrors([
            'logo_url' => 'نشانی لوگو باید یک آدرس کامل باشد که با https:// یا http:// شروع شود.',
        ]);

    expect(shopSettingSection($this->tenant, 'print'))->toBe([]);
});

// -------------------------------------------------------------- پیامک ----

it('opens the messaging screen with every automation off', function (): void {
    /*
    | Not a screen that failed to load: `MessagingSettings` defaults every automation to
    | OFF and means it, because a shop must never wake up to texts it did not authorise,
    | sent on credit it did not agree to spend.
    */
    $this->actingAs($this->owner)
        ->get(appUrl('/settings/messaging'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Settings::Settings/Messaging')
            ->has('automations', count(AutomationKey::cases()))
            ->where('automations.0.key', AutomationKey::InvoiceIssued->value)
            ->where('automations.0.enabled', false)
            ->where('automations.0.swept', false)
            ->where('quiet.until_hour', TenantShopSettings::DEFAULT_QUIET_UNTIL_HOUR)
            ->where('quiet.from_hour', TenantShopSettings::DEFAULT_QUIET_FROM_HOUR)
        );
});

it('writes a real boolean for every automation, on and off alike', function (): void {
    $this->actingAs($this->owner)
        ->from(appUrl('/settings/messaging'))
        ->put(appUrl('/settings/messaging'), [
            'automations' => [AutomationKey::Birthday->value, AutomationKey::RepairReady->value],
            'quiet_until_hour' => 8,
            'quiet_from_hour' => 22,
        ])
        ->assertRedirect(appUrl('/settings/messaging'))
        ->assertSessionHasNoErrors();

    $messaging = shopSettingSection($this->tenant, 'messaging');

    /** @var array<string, mixed> $automations */
    $automations = is_array($messaging['automations'] ?? null) ? $messaging['automations'] : [];

    /*
    | `MessagingSettings` reads `=== true`, not a truthy check — `'1'`, `1` and `'yes'`
    | all mean off. So every case gets a genuine bool, and an unticked one is stored as an
    | explicit false rather than left absent.
    */
    expect($automations)->toHaveCount(count(AutomationKey::cases()));

    foreach (AutomationKey::cases() as $key) {
        expect($automations[$key->value])->toBeBool();
    }

    expect($automations[AutomationKey::Birthday->value])->toBeTrue()
        ->and($automations[AutomationKey::RepairReady->value])->toBeTrue()
        ->and($automations[AutomationKey::InvoiceIssued->value])->toBeFalse();

    /** @var MessagingSettings $resolved */
    $resolved = app(TenantContext::class)->runFor(
        $this->tenant,
        fn (): MessagingSettings => app(ShopSettings::class)->messaging(),
    );

    expect($resolved->isEnabled(AutomationKey::Birthday))->toBeTrue()
        ->and($resolved->isEnabled(AutomationKey::InvoiceIssued))->toBeFalse()
        ->and($resolved->quietUntilHour)->toBe(8)
        ->and($resolved->quietFromHour)->toBe(22)
        // 3am is inside the window the shop just drew, and that is the whole point of it.
        ->and($resolved->isQuietAt(3))->toBeTrue()
        ->and($resolved->isQuietAt(14))->toBeFalse();
});

it('reads an empty list as "switch everything off"', function (): void {
    // Unticking every box is a legitimate instruction and has to survive the trip as one.
    // `present` rather than `required` is what lets the empty array through; this form
    // carries no file, so Inertia sends JSON and an empty array is expressible.
    $this->actingAs($this->owner)
        ->from(appUrl('/settings/messaging'))
        ->put(appUrl('/settings/messaging'), [
            'automations' => [],
            'quiet_until_hour' => 9,
            'quiet_from_hour' => 21,
        ])
        ->assertSessionHasNoErrors();

    $messaging = shopSettingSection($this->tenant, 'messaging');

    /** @var array<string, mixed> $automations */
    $automations = is_array($messaging['automations'] ?? null) ? $messaging['automations'] : [];

    expect($automations)->toHaveCount(count(AutomationKey::cases()));

    foreach ($automations as $enabled) {
        expect($enabled)->toBeFalse();
    }
});

it('refuses a quiet window that would silence the shop entirely', function (): void {
    /*
    | `isQuietAt()` is `$hour < $until || $hour >= $from`. With until 21 and from 9 every
    | hour of the day satisfies one side, the sweep never runs, and there is nothing on
    | any screen to explain it. Equal hours do the same.
    */
    $this->actingAs($this->owner)
        ->from(appUrl('/settings/messaging'))
        ->put(appUrl('/settings/messaging'), [
            'automations' => [],
            'quiet_until_hour' => 21,
            'quiet_from_hour' => 9,
        ])
        ->assertSessionHasErrors([
            'quiet_from_hour' => 'ساعت پایان ارسال باید بعد از ساعت شروع باشد؛ در غیر این صورت هیچ پیامک زمان‌بندی‌شده‌ای ارسال نخواهد شد.',
        ]);

    expect(shopSettingSection($this->tenant, 'messaging'))->toBe([]);
});

it('refuses an automation key the product does not have', function (): void {
    $this->actingAs($this->owner)
        ->from(appUrl('/settings/messaging'))
        ->put(appUrl('/settings/messaging'), [
            'automations' => ['invoice.issued', 'something.invented'],
            'quiet_until_hour' => 9,
            'quiet_from_hour' => 21,
        ])
        ->assertSessionHasErrors('automations.1');

    expect(shopSettingSection($this->tenant, 'messaging'))->toBe([]);
});

// --------------------------------------------------- دسترسی و فهرست ----

it('lists both new doors for somebody who may open them', function (): void {
    $this->actingAs($this->owner)
        ->get(appUrl('/settings'))
        ->assertOk()
        ->assertInertia(function ($page): void {
            expect(settingsHubKeys($page))->toContain('print', 'messaging');
        });
});

it('shows neither door to a cashier, who may not open either', function (): void {
    $this->actingAs($this->cashier)
        ->get(appUrl('/settings'))
        ->assertOk()
        ->assertInertia(function ($page): void {
            expect(settingsHubKeys($page))
                ->not->toContain('print')
                ->and(settingsHubKeys($page))->not->toContain('messaging');
        });

    // And the hub is not the gate — the screens refuse on their own.
    $this->actingAs($this->cashier)->get(appUrl('/settings/print'))->assertForbidden();
    $this->actingAs($this->cashier)->get(appUrl('/settings/messaging'))->assertForbidden();
});

it('refuses a write from a role without settings.update', function (): void {
    $this->actingAs($this->cashier)
        ->from(appUrl('/settings'))
        ->put(appUrl('/settings/print'), ['logo_url' => '', 'footer_terms' => '', 'show_qr' => false])
        ->assertForbidden();

    $this->actingAs($this->cashier)
        ->from(appUrl('/settings'))
        ->put(appUrl('/settings/messaging'), [
            'automations' => [AutomationKey::Birthday->value],
            'quiet_until_hour' => 9,
            'quiet_from_hour' => 21,
        ])
        ->assertForbidden();

    expect(shopSettingSection($this->tenant, 'print'))->toBe([])
        ->and(shopSettingSection($this->tenant, 'messaging'))->toBe([]);
});

/**
 * The destination keys the settings hub rendered.
 *
 * @return list<string>
 */
function settingsHubKeys(object $page): array
{
    /** @var array{props: array{groups: array<int, array{items: array<int, array{key: string}>}>}} $rendered */
    $rendered = $page->toArray();

    $keys = [];

    foreach ($rendered['props']['groups'] as $group) {
        foreach ($group['items'] as $item) {
            $keys[] = $item['key'];
        }
    }

    return $keys;
}

// ------------------------------------------------------------ ایزوله ----

it('turns away a user of one shop asking for another shop\'s settings screen', function (): void {
    $other = Tenant::factory()->withDomain()->create();
    subscribe($other, 'pro');
    app(SubscriptionResolver::class)->forget();

    /*
    | Since ADR 0017 there is no second address to ask at, so the session says which shop
    | the request claims to be for. Sign in first — which pins the owner's own tenant —
    | and forge second; reversed, `actingAs()` would put the right tenant back and the
    | request would legitimately succeed while the test claimed otherwise.
    */
    $this->actingAs($this->owner);

    actingForTenant($other)->get(appUrl('/settings/print'))->assertRedirect(appUrl('/login'));

    $this->actingAs($this->owner);

    actingForTenant($other)
        ->put(appUrl('/settings/print'), ['logo_url' => '', 'footer_terms' => '', 'show_qr' => false])
        ->assertRedirect(appUrl('/login'));

    expect(shopSettingSection($other, 'print'))->toBe([]);
})->group('isolation');

it('writes only into the shop the request belongs to', function (): void {
    $other = Tenant::factory()->withDomain()->create();
    subscribe($other, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($other);

    /** @var User $neighbour */
    $neighbour = app(TenantContext::class)->runFor($other, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Owner');
        $user->refresh();

        return $user;
    });

    $this->actingAs($this->owner)
        ->from(appUrl('/settings/print'))
        ->put(appUrl('/settings/print'), [
            'logo_url' => 'https://example.test/shop-a.png',
            'footer_terms' => 'شرایط فروشگاه الف.',
            'show_qr' => false,
        ])
        ->assertSessionHasNoErrors();

    // The neighbour's document is untouched, and their own screen shows their own
    // defaults — the QR still on, no terms, no logo.
    expect(shopSettingSection($other, 'print'))->toBe([]);

    $this->actingAs($neighbour)
        ->get(appUrl('/settings/print'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('print.logo_url', null)
            ->where('print.footer_terms', null)
            ->where('print.show_qr', true)
        );

    // And the first shop still has exactly what it saved.
    expect(shopSettingSection($this->tenant, 'print')['logo_url'])->toBe('https://example.test/shop-a.png');
})->group('isolation');
