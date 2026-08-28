<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Widgets\RevenueOverview;
use App\Filament\Widgets\SubscriptionsByPlan;
use App\Support\Tenancy\PlatformPanelContext;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The super-admin panel — Hamyar staff, not shop staff.
 *
 * Three deliberate constraints:
 *
 * 1. **Central domain only.** `->domain()` pins it to `config('app.domain')`, so
 *    `/admin` does NOT exist on any shop's subdomain. Without this, every tenant would
 *    serve a login form for the platform panel on their own hostname — which is both a
 *    phishing surface and an invitation to credential-stuff.
 * 2. **Its own guard.** `platform_users`, never the tenant `users` table, so a
 *    compromised shop login cannot reach here (see PlatformUser).
 * 3. **Platform read context.** Panel requests run inside the `app.platform` flag so
 *    billing tables are readable across tenants — and *only* those, since the flag is
 *    narrow by construction (ADR 0002 amendment). Shop data stays invisible unless a
 *    resource explicitly enters a tenant context.
 *
 * The apex domain is never hardcoded (golden rule 1b).
 */
final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->domain(config()->string('app.domain'))
            ->authGuard('platform')
            ->login()
            ->brandName('مدیریت همیار')
            ->colors([
                // Matches the product's own brand token rather than a Filament default,
                // so staff screenshots in a support thread look like the same product.
                'primary' => Color::hex('#0066cc'),
            ])
            ->font('Vazirmatn')
            ->maxContentWidth(Width::Full)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([Dashboard::class])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                RevenueOverview::class,
                SubscriptionsByPlan::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                // Must run for guests too: the login screen and the panel share a
                // request pipeline, and a resource resolving a billing row before auth
                // would otherwise see nothing and look broken.
                PlatformPanelContext::class,
            ])
            ->authMiddleware([
                \Filament\Http\Middleware\Authenticate::class,
            ]);
    }
}
