<?php

declare(strict_types=1);

namespace App\Modules\Sales\Providers;

use App\Modules\Sales\Contracts\InvoiceSettlementGuard;
use App\Modules\Sales\Contracts\NothingBlocksSettlement;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Policies\SalesInvoicePolicy;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Support\Facades\Gate;

/**
 * Sales module.
 *
 * Spec: docs/specs/sales.md
 *
 * Migrations, views, translations and routes are picked up by convention — see
 * App\Support\Modules\ModuleServiceProvider. Register bindings and event listeners
 * here. Cross-module calls go through domain events or a public interface bound
 * below; never by reaching into another module's namespace (ADR 0003, enforced by
 * tests/Arch/ModuleBoundariesTest.php).
 */
final class SalesServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        /*
        | The default answer, for a deployment with no Cheques module.
        |
        | A null object rather than a nullable dependency: every caller would otherwise
        | carry the same `?? 0`, and the first one to forget it would be the one that
        | mattered.
        |
        | `bindIf`, not `bind`, and that is load-bearing. Module providers are discovered
        | in directory order, so Cheques may register before or after this one. With
        | `bind` on both sides the last writer wins and the real implementation is
        | silently replaced by the null object roughly half the time — a bug that would
        | show up as a credit check quietly passing. `bindIf` yields to whatever is
        | already there, so the answer is the same whichever order they load in.
        */
        $this->app->bindIf(InvoiceSettlementGuard::class, NothingBlocksSettlement::class);

        //
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(SalesInvoice::class, SalesInvoicePolicy::class);
    }
}
