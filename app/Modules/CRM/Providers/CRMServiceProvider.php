<?php

declare(strict_types=1);

namespace App\Modules\CRM\Providers;

use App\Modules\CRM\Listeners\CreateDefaultAccount;
use App\Modules\CRM\Models\Party;
use App\Modules\CRM\Policies\PartyPolicy;
use App\Modules\CRM\Services\PartyTimeline;
use App\Modules\Platform\Events\TenantProvisioned;
use App\Support\Documents\DocumentReference;
use App\Support\Documents\DocumentRegistry;
use App\Support\Documents\DocumentType;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Timeline\TimelineRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

/**
 * CRM module.
 *
 * Spec: docs/specs/c-r-m.md
 *
 * Migrations, views, translations and routes are picked up by convention — see
 * App\Support\Modules\ModuleServiceProvider. Register bindings and event listeners
 * here. Cross-module calls go through domain events or a public interface bound
 * below; never by reaching into another module's namespace (ADR 0003, enforced by
 * tests/Arch/ModuleBoundariesTest.php).
 */
final class CRMServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(Party::class, PartyPolicy::class);

        // CRM contributes its own half of the timeline through the same registry every
        // other module uses, rather than reading its tables directly in the controller.
        // That keeps one assembly path, so a bug in ordering or windowing is one bug.
        $this->app->make(TimelineRegistry::class)->contribute(
            'CRM',
            fn (int $partyId, ?CarbonImmutable $from, ?CarbonImmutable $to): array => $this->app
                ->make(PartyTimeline::class)
                ->for($partyId, $from, $to)
        );

        // Registered under a short key rather than the class name: `product_units`
        // carries a plain `acquired_from_party_id`, not a morph pair, so the screen
        // asking for it knows it wants a party but must not know what class that is.
        $this->app->make(DocumentRegistry::class)->register(
            DocumentType::PARTY,
            static fn (array $ids): array => Party::query()
                ->whereKey($ids)
                ->get(['id', 'name'])
                ->mapWithKeys(fn (Party $party): array => [
                    $party->id => new DocumentReference($party->name),
                ])
                ->all()
        );

        Event::listen(TenantProvisioned::class, CreateDefaultAccount::class);
    }
}
