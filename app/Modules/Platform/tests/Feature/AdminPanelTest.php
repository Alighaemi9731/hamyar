<?php

declare(strict_types=1);

use App\Filament\Resources\Plans\Pages\EditPlan;
use App\Filament\Resources\Plans\Schemas\PlanForm;
use App\Filament\Widgets\QuotaPressure;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Announcement;
use App\Modules\Platform\Models\Plan;
use App\Modules\Platform\Models\PlatformUser;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\ImpersonationService;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\Quota\LimitResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Money;
use App\Support\Tenancy\TenantContext;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->staff = PlatformUser::factory()->create(['is_active' => true]);
    $this->admin = centralUrl('/admin');
});

afterEach(fn () => app(TenantContext::class)->forget());

/* ---------------------------------------------------------------- access -- */

it('keeps the panel off the application host', function (): void {
    // Was "off every shop's subdomain" until ADR 0017 removed those; the guarantee moved
    // rather than disappeared. Serving a platform login form at the address every
    // shopkeeper signs in at would be a phishing surface and an invitation to
    // credential-stuff — one form, two user tables, and no way for a human to tell which
    // one a page is asking for.
    $this->actingAs($this->staff, 'platform')
        ->get(appUrl().'/admin')
        ->assertNotFound();
});

it('sends a guest to the panel login', function (): void {
    $this->get($this->admin)->assertRedirect();
});

it('lets active staff in', function (): void {
    $this->actingAs($this->staff, 'platform')->get($this->admin)->assertOk();
});

it('locks out a deactivated staff account immediately', function (): void {
    // Filament re-checks on every request, so revoking access does not wait for the
    // session to expire — which is the case that actually matters.
    $this->staff->update(['is_active' => false]);

    $this->actingAs($this->staff, 'platform')->get($this->admin)->assertForbidden();
});

it('does not accept a tenant user on the platform guard', function (): void {
    $tenant = Tenant::factory()->withDomain()->create();
    app(TenantProvisioner::class)->seedRoles($tenant);

    $shopUser = app(TenantContext::class)->runFor($tenant, fn (): User => User::factory()->create());

    // Separate guards and separate tables: a shop login can never reach here.
    $this->actingAs($shopUser)->get($this->admin)->assertRedirect();
});

/* -------------------------------------------------------------- billing -- */

it('reads billing across every tenant', function (): void {
    $alpha = Tenant::factory()->withDomain()->create();
    $beta = Tenant::factory()->withDomain()->create();

    subscribe($alpha, 'pro');
    subscribe($beta, 'basic');

    // The panel middleware sets the platform flag; without it these rows are invisible
    // and the whole panel looks empty.
    $this->actingAs($this->staff, 'platform')
        ->get($this->admin.'/subscriptions')
        ->assertOk()
        ->assertSee($alpha->name)
        ->assertSee($beta->name);
});

it('does NOT expose shop data through the panel context', function (): void {
    pest()->group('isolation');

    $tenant = Tenant::factory()->withDomain()->create();
    app(TenantProvisioner::class)->seedRoles($tenant);

    app(TenantContext::class)->runFor(
        $tenant,
        fn () => User::factory()->create(['name' => 'کاربر محرمانه فروشگاه'])
    );

    // The platform flag opens billing and nothing else (ADR 0002 amendment). If this
    // ever fails, the panel has quietly become a cross-tenant data browser.
    $this->actingAs($this->staff, 'platform')
        ->get($this->admin.'/tenants')
        ->assertOk()
        ->assertDontSee('کاربر محرمانه فروشگاه');
});

/* ---------------------------------------------------------------- plans -- */

it('stores an edited price as rial while showing toman', function (): void {
    // `basic` is free since DECISION GATE 6, so the assertion needs a plan with a price
    // on it — a zero would pass while proving nothing about the conversion.
    $pro = Plan::query()->where('code', 'pro')->firstOrFail();

    $this->actingAs($this->staff, 'platform')
        ->get($this->admin.'/plans')
        ->assertOk()
        // Staff read prices in toman; a rial figure on this screen is off by 10x to a
        // human eye and is how a mispricing gets approved.
        ->assertSee(Money::formatWithUnit($pro->price));
});

it('edits a plan limit and applies it to the shops on that plan', function (): void {
    // The panel is where limits live (Gate 2's rule, now load-bearing: a limit IS the
    // product). Saving one has to reach the shops on that plan without waiting for each
    // worker to restart — which is what the entitlement-version bump is for.
    $tenant = Tenant::factory()->withDomain()->create();
    subscribe($tenant, 'basic');

    $basic = Plan::query()->where('code', 'basic')->firstOrFail();

    $before = app(LimitResolver::class)->forTenant(idOf($tenant), 'sales.invoices');

    Livewire::actingAs($this->staff, 'platform')
        ->test(EditPlan::class, ['record' => idOf($basic)])
        ->fillForm([PlanForm::fieldFor('sales.invoices') => 7])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($before)->not->toBe(7);
    // The row, and then the number the SHOP actually gets — they are different
    // questions, and only the second one proves the bump reached the tenant.
    expect($basic->fresh()?->limit('sales.invoices'))->toBe(7);
    expect(app(LimitResolver::class)->forTenant(idOf($tenant), 'sales.invoices'))->toBe(7);
});

/* -------------------------------------------------------- impersonation -- */

it('audits impersonation into the shop own activity log', function (): void {
    $tenant = Tenant::factory()->withDomain()->create();
    app(TenantProvisioner::class)->seedRoles($tenant);

    $owner = app(TenantContext::class)->runFor($tenant, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Owner');

        return $user;
    });

    $this->actingAs($this->staff, 'platform');

    $url = app(ImpersonationService::class)->start($tenant, 'تیکت پشتیبانی ۱۲۳۴');

    expect($url)->toBeString();
    /*
    | Rewritten for ADR 0017, not relaxed. It used to assert the link carried the SHOP's
    | own hostname, because that hostname was what told the receiving end which shop the
    | link belonged to. Per-shop addresses are gone, so the assertion now names the host
    | the link must actually be minted on — `app.<apex>`, the one every shop is reached
    | at, and the one the signature is verified against.
    |
    | Still the same guarantee underneath: the signature covers the host, so a link minted
    | anywhere else (the central domain the panel is served from, most plausibly) fails
    | validation at the only place it is ever opened, with a 403 that explains nothing.
    */
    expect($url)->toStartWith(appUrl().'/impersonate/');

    $activity = app(TenantContext::class)->runFor(
        $tenant,
        fn (): ?Activity => Activity::query()->where('log_name', 'impersonation')->latest('id')->first()
    );

    expect($activity)->not->toBeNull();
    expect($activity?->properties['reason'] ?? null)->toBe('تیکت پشتیبانی ۱۲۳۴');
    expect($activity?->properties['platform_user_email'] ?? null)->toBe($this->staff->email);
    // The shop must be able to read it — that is what makes the feature defensible.
    expect($activity?->getAttribute('tenant_id'))->toBe($tenant->getKey());

    unset($owner);
});

it('refuses to impersonate a shop with no active owner', function (): void {
    $tenant = Tenant::factory()->withDomain()->create();
    app(TenantProvisioner::class)->seedRoles($tenant);

    $this->actingAs($this->staff, 'platform');

    expect(app(ImpersonationService::class)->start($tenant, 'دلیل کافی برای ورود'))->toBeNull();
});

it('rejects an unsigned impersonation link', function (): void {
    $tenant = Tenant::factory()->withDomain()->create();
    app(TenantProvisioner::class)->seedRoles($tenant);

    $owner = app(TenantContext::class)->runFor($tenant, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Owner');

        return $user;
    });

    // The signature IS the authorisation — nobody is logged in when the link is used.
    /** @var int $ownerId */
    $ownerId = $owner->getKey();

    $this->get(appUrl().'/impersonate/'.$ownerId)
        ->assertForbidden();

    $this->assertGuest();
});

it('logs the owner in through a valid signed link', function (): void {
    $tenant = Tenant::factory()->withDomain()->create();
    app(TenantProvisioner::class)->seedRoles($tenant);

    $owner = app(TenantContext::class)->runFor($tenant, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Owner');

        return $user;
    });

    $this->actingAs($this->staff, 'platform');
    $url = app(ImpersonationService::class)->start($tenant, 'تیکت پشتیبانی ۵۶۷۸');

    // Fresh session: this is the customer-facing side of the hand-off.
    auth('platform')->logout();

    $this->get((string) $url)->assertRedirect(appUrl().'/dashboard');

    expect(session('impersonating'))->toBeTrue();

    // Asserted against the session the request actually wrote, rather than through the
    // guard. Two things stand between this test and `assertAuthenticatedAs`, and both are
    // correct behaviour: the guard was resolved before anyone logged in and caches a null
    // user, and the tenant context ends with the request, so re-resolving a tenant User
    // outside one is *supposed* to return nothing. The session key is the durable fact —
    // it is what the next real request authenticates from.
    /** @var Illuminate\Auth\SessionGuard $web */
    $web = auth('web');

    expect(session($web->getName()))->toBe($owner->getKey());
});

/* -------------------------------------------------------- announcements -- */

it('shows a global announcement to every shop', function (): void {
    Announcement::query()->create([
        'title' => 'قطعی برنامه‌ریزی‌شده',
        'body' => 'سامانه جمعه از ساعت ۲ تا ۴ بامداد در دسترس نخواهد بود.',
        'level' => Announcement::LEVEL_WARNING,
    ]);

    $tenant = Tenant::factory()->withDomain()->create();
    app(TenantProvisioner::class)->seedRoles($tenant);
    subscribe($tenant, 'pro');

    $user = app(TenantContext::class)->runFor($tenant, fn (): User => User::factory()->create());

    // A panel that writes notices nobody sees is not a feature.
    $this->actingAs($user)
        ->get(appUrl().'/dashboard')
        ->assertInertia(fn ($page) => $page->has('announcements', 1));
});

it('shows a targeted announcement only to its shop', function (): void {
    pest()->group('isolation');

    $mine = Tenant::factory()->withDomain()->create();
    $other = Tenant::factory()->withDomain()->create();

    app(TenantProvisioner::class)->seedRoles($mine);
    app(TenantProvisioner::class)->seedRoles($other);
    subscribe($mine, 'pro');
    subscribe($other, 'pro');

    Announcement::query()->create([
        'tenant_id' => $mine->getKey(),
        'title' => 'پرداخت شما ناموفق بود',
        'body' => 'لطفاً روش پرداخت را بررسی کنید.',
        'level' => Announcement::LEVEL_CRITICAL,
    ]);

    $intruder = app(TenantContext::class)->runFor($other, fn (): User => User::factory()->create());

    // `announcements` is a central table where `tenant_id` means "only this shop",
    // the inverse of everywhere else — so it gets its own leak test rather than
    // relying on an RLS policy it does not have.
    $this->actingAs($intruder)
        ->get(appUrl().'/dashboard')
        ->assertInertia(fn ($page) => $page->has('announcements', 0));
});

it('hides an announcement outside its window', function (): void {
    Announcement::query()->create([
        'title' => 'کمپین نوروزی',
        'body' => 'تخفیف ویژه.',
        'level' => Announcement::LEVEL_INFO,
        'ends_at' => now()->subHour(),
    ]);

    $tenant = Tenant::factory()->withDomain()->create();
    app(TenantProvisioner::class)->seedRoles($tenant);
    subscribe($tenant, 'pro');

    $user = app(TenantContext::class)->runFor($tenant, fn (): User => User::factory()->create());

    $this->actingAs($user)
        ->get(appUrl().'/dashboard')
        ->assertInertia(fn ($page) => $page->has('announcements', 0));
});

/* ------------------------------------------------------ the panel renders -- */

/**
 * The dashboard, actually rendered.
 *
 * ## Why this exists
 *
 * `QuotaPressure` threw a 500 on every load and shipped that way. Nothing in 1,200 tests
 * noticed, because nothing had ever rendered this page: the suite asserted access control
 * (who may reach `/admin`) and resource forms, and never once asked whether the thing a
 * platform admin sees when they sign in comes back at all.
 *
 * It failed on `order by "usage_events"."id"` against a `group by metric` — Filament
 * appends the record key as a tiebreaker, and Postgres refuses a bare column there. Being
 * a *widget*, the exception surfaced as a full-screen overlay across the panel rather than
 * as one broken card, so the whole thing was unusable.
 *
 * A 200 on this page is a weak-looking assertion that would have caught it on day one.
 */
it('renders the platform dashboard shell', function (): void {
    $this->actingAs($this->staff, 'platform')
        ->get($this->admin)
        ->assertOk();
});

/**
 * The widget, actually executed — and this is the assertion that has teeth.
 *
 * A plain `GET /admin` does **not** cover it. Filament loads widgets lazily over
 * Livewire, so the page shell comes back 200 while the widget's query has not run yet;
 * the test above passes with the bug fully present. That was checked by putting the bug
 * back, not assumed — and it is the trap `docs/testing.md` calls green without witness.
 *
 * No fixture rows on purpose. The failure is in the ORDER BY, which Postgres rejects when
 * it plans the statement, so an empty table reproduces it exactly. Seeding would add a
 * tenancy dance that has nothing to do with what is being tested.
 */
it('runs the quota-pressure widget query without Postgres refusing it', function (): void {
    Livewire::actingAs($this->staff, 'platform')
        ->test(QuotaPressure::class)
        ->assertOk();
});
