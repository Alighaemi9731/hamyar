<?php

declare(strict_types=1);

namespace App\Modules\Cheques\Providers;

use App\Modules\Cheques\Models\Cheque;
use App\Modules\Cheques\Policies\ChequePolicy;
use App\Modules\Cheques\Services\ChequeExposure;
use App\Modules\Cheques\Services\LiveChequeGuard;
use App\Modules\CRM\Contracts\PartyExposure;
use App\Modules\Sales\Contracts\InvoiceSettlementGuard;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Quota\Metric;
use App\Support\Quota\MetricRegistry;
use App\Support\Quota\Window;
use Illuminate\Support\Facades\Gate;

/**
 * Cheques module.
 *
 * Spec: docs/specs/cheques.md
 *
 * Migrations, views, translations and routes are picked up by convention — see
 * App\Support\Modules\ModuleServiceProvider. Register bindings and event listeners
 * here. Cross-module calls go through domain events or a public interface bound
 * below; never by reaching into another module's namespace (ADR 0003, enforced by
 * tests/Arch/ModuleBoundariesTest.php).
 */
final class ChequesServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        /*
        | What this module meters.
        |
        | Declared here rather than in Platform so shipping a metered action is a change
        | in one module (golden rule 6): the pricing page, the Filament limits editor, the
        | usage meters and the analytics all iterate `MetricRegistry` and pick this up
        | without Platform knowing the key exists.
        |
        | `afterResolving` rather than resolving the registry now: provider discovery
        | order is a directory listing, and a registry built before this provider ran
        | would silently be missing these — the `bindIf` lesson, applied to a registry.
        */
        $this->app->afterResolving(MetricRegistry::class, static function (MetricRegistry $registry): void {
            $registry->register(
                new Metric('cheques.cheques', 'ثبت چک', Window::Month, 'cheques', unitFa: 'چک', position: 70),
            );
        });

        /*
        | Cheques answers two questions other modules must not ask it directly.
        |
        | CRM's credit check needs a party's off-ledger exposure; Sales needs to know
        | whether a live cheque blocks a void. Both declare an interface and neither knows
        | this module exists — the dependency points inward, which is what keeps them
        | working in a deployment where cheques are switched off (golden rule 6, ADR 0003).
        */
        $this->app->bind(PartyExposure::class, ChequeExposure::class);
        $this->app->bind(InvoiceSettlementGuard::class, LiveChequeGuard::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(Cheque::class, ChequePolicy::class);
    }
}
