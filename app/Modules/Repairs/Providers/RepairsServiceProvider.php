<?php

declare(strict_types=1);

namespace App\Modules\Repairs\Providers;

use App\Modules\Repairs\Models\RepairTicket;
use App\Modules\Repairs\Policies\RepairTicketPolicy;
use App\Support\Audit\AuditSubjects;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Quota\Metric;
use App\Support\Quota\MetricRegistry;
use App\Support\Quota\Window;
use Illuminate\Support\Facades\Gate;

/**
 * Repairs module.
 *
 * Spec: docs/specs/repairs.md
 *
 * Migrations, views, translations and routes are picked up by convention — see
 * App\Support\Modules\ModuleServiceProvider. Register bindings and event listeners
 * here. Cross-module calls go through domain events or a public interface bound
 * below; never by reaching into another module's namespace (ADR 0003, enforced by
 * tests/Arch/ModuleBoundariesTest.php).
 */
final class RepairsServiceProvider extends ModuleServiceProvider
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
                new Metric('repairs.tickets', 'قبض پذیرش تعمیر', Window::Month, 'repairs', unitFa: 'قبض', position: 40, landing: true),
            );
        });

        //
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(RepairTicket::class, RepairTicketPolicy::class);

        // Registered for the label, not because the ticket carries `Auditable`.
        // Every passcode reveal since Phase 6 has written an activity row with the
        // ticket as its subject, so the filter needs a Persian name for it — without
        // this those rows are filterable only by typing a class name.
        //
        // The ticket itself is deliberately NOT audited attribute-by-attribute: a
        // repair moves through six statuses and would be the highest-volume subject
        // in the product, and `ticket_status_histories` already records exactly that
        // — from_status, to_status, actor_id, note, created_at — in the module that
        // owns it. See ADR 0014.
        $this->app->make(AuditSubjects::class)->register(
            'repair-ticket', RepairTicket::class, 'تیکت تعمیر', 60,
            static fn (int $id): ?string => RepairTicket::query()->find($id)?->code,
        );
    }
}
