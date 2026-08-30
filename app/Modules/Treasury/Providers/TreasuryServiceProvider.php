<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Providers;

use App\Modules\CRM\Models\Account;
use App\Modules\Treasury\Models\RecurringTemplate;
use App\Modules\Treasury\Models\RentalContract;
use App\Modules\Treasury\Policies\AccountPolicy;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Quota\Metric;
use App\Support\Quota\MetricRegistry;
use App\Support\Quota\Window;
use Illuminate\Support\Facades\Gate;

/**
 * Treasury module.
 *
 * Spec: docs/specs/treasury.md
 *
 * Migrations, views, translations and routes are picked up by convention — see
 * App\Support\Modules\ModuleServiceProvider. Register bindings and event listeners
 * here. Cross-module calls go through domain events or a public interface bound
 * below; never by reaching into another module's namespace (ADR 0003, enforced by
 * tests/Arch/ModuleBoundariesTest.php).
 */
final class TreasuryServiceProvider extends ModuleServiceProvider
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
                new Metric('treasury.transfers', 'انتقال بین حساب‌ها', Window::Month, 'treasury', unitFa: 'انتقال', position: 75),
                new Metric('treasury.cash_transactions', 'ثبت هزینه/درآمد', Window::Month, 'treasury', unitFa: 'سند', position: 76),

                new Metric(
                    'treasury.recurring_templates', 'الگوی تکراری', Window::Total, 'treasury',
                    unitFa: 'الگو', position: 77,
                    measure: static fn (int $tenantId): int => RecurringTemplate::query()
                        ->where('tenant_id', $tenantId)
                        ->where('is_active', true)
                        ->count(),
                ),

                new Metric(
                    'treasury.rental_contracts', 'قرارداد اجاره', Window::Total, 'treasury',
                    unitFa: 'قرارداد', position: 78,
                    measure: static fn (int $tenantId): int => RentalContract::query()
                        ->where('tenant_id', $tenantId)
                        ->whereNull('terminated_on')
                        ->count(),
                ),
            );
        });

        //
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(Account::class, AccountPolicy::class);
    }
}
