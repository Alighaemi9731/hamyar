<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Providers;

use App\Modules\Storefront\Models\PriceListLink;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Quota\Metric;
use App\Support\Quota\MetricRegistry;
use App\Support\Quota\Window;

/**
 * Storefront module.
 *
 * Spec: docs/specs/storefront.md
 *
 * Migrations, views, translations and routes are picked up by convention — see
 * App\Support\Modules\ModuleServiceProvider. Register bindings and event listeners
 * here. Cross-module calls go through domain events or a public interface bound
 * below; never by reaching into another module's namespace (ADR 0003, enforced by
 * tests/Arch/ModuleBoundariesTest.php).
 */
final class StorefrontServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        /*
        | What this module meters. Declared here rather than in Platform so shipping a
        | metered action is a change in one module (golden rule 6), and registered with
        | `afterResolving` so provider discovery order — a directory listing — cannot
        | leave them out.
        */
        $this->app->afterResolving(MetricRegistry::class, static function (MetricRegistry $registry): void {
            $registry->register(
                /*
                | Live links only: revoked and expired ones give the slot back. A shop
                | that closes a leaked price list must not be punished for it — that is
                | exactly the act we want to be free.
                */
                new Metric(
                    'storefront.price_list_links', 'لینک لیست قیمت', Window::Total, 'storefront',
                    unitFa: 'لینک', position: 98,
                    measure: static fn (int $tenantId): int => PriceListLink::query()
                        ->where('tenant_id', $tenantId)
                        ->whereNull('revoked_at')
                        ->where('expires_at', '>', now())
                        ->count(),
                ),
            );
        });

        //
    }
}
