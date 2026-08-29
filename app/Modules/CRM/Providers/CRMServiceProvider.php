<?php

declare(strict_types=1);

namespace App\Modules\CRM\Providers;

use App\Modules\CRM\Contracts\NoPartyExposure;
use App\Modules\CRM\Contracts\PartyExposure;
use App\Modules\CRM\Listeners\CreateDefaultAccount;
use App\Modules\CRM\Models\Party;
use App\Modules\CRM\Policies\PartyPolicy;
use App\Modules\CRM\Services\PartyTimeline;
use App\Modules\Platform\Events\TenantProvisioned;
use App\Support\Audit\AuditSubjects;
use App\Support\Documents\DocumentReference;
use App\Support\Documents\DocumentRegistry;
use App\Support\Documents\DocumentType;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Quota\Metric;
use App\Support\Quota\MetricRegistry;
use App\Support\Quota\Window;
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
                new Metric('crm.parties', 'طرف حساب جدید', Window::Month, 'crm', unitFa: 'طرف حساب', position: 50, landing: true),
                new Metric('crm.follow_ups', 'پیگیری', Window::Month, 'crm', unitFa: 'پیگیری', position: 51),
            );
        });

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
        $this->app->bindIf(PartyExposure::class, NoPartyExposure::class);

        //
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(Party::class, PartyPolicy::class);

        // A party's credit limit and opening balance are audited: they are the two
        // numbers a shop argues about, and «کی این سقف را بالا برد؟» has no other
        // answer — the balance itself is a SUM over ledger_entries and carries no
        // record of who moved the ceiling.
        $this->app->make(AuditSubjects::class)->register(
            'party', Party::class, 'طرف حساب', 40,
            static fn (int $id): ?string => Party::query()->find($id)?->name,
        );

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
