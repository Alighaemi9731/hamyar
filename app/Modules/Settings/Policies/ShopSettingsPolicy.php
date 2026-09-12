<?php

declare(strict_types=1);

namespace App\Modules\Settings\Policies;

use App\Modules\Identity\Models\User;

/**
 * Who may read, and who may change, what the shop has decided about itself.
 *
 * ## The subject is the contract, not a model
 *
 * Registered against {@see \App\Support\Settings\ShopSettings} — the shared-kernel
 * interface — rather than against a model, because there is no settings model to point
 * at: the values live in `tenants.settings`, and `tenants` belongs to Platform. A policy
 * Settings registered on Platform's `Tenant` would make this module the authority on
 * somebody else's model (ADR 0003), and would collide the day Platform wants its own.
 * The interface is exactly the resource being edited, it is shared-kernel rather than
 * another module's, and Settings already binds its implementation — so it is the honest
 * thing to hang this on. Callers pass the class name:
 * `$user->can('update', ShopSettings::class)`.
 *
 * ## Who gets `settings.update`, and why it is not just the Owner
 *
 * `settings.view` and `settings.update` have been in {@see
 * \App\Modules\Identity\Support\PermissionCatalogue} since Phase 2 and, until these
 * screens, **nothing checked them** — which that catalogue's own docblock names as worse
 * than having no permission at all, because it reads as protection that is not there.
 * This is the first thing to actually enforce the write half.
 *
 * By the seeded roles that means the **Owner and the Manager**, and nobody else. The
 * Manager is deliberate: «مدیر» is the person who runs the shop day to day, and the two
 * things these screens hold — the wording on the shop's own receipts, and whether the
 * shop texts its customers — are exactly the day-to-day decisions an owner delegates when
 * they are not standing behind the counter. Withholding it would mean the only person who
 * can fix a wrong warranty line on a printed invoice is the one person who may be away.
 *
 * The floor is that it stays off the counter roles. A Salesperson, a Cashier, a
 * Technician and a Warehousekeeper hold neither permission: a messaging switch spends the
 * shop's SMS credit on its customers' phones, and the footer terms are the text that gets
 * read back at the shop in an argument. Neither is a counter decision.
 *
 * A shop that disagrees with any of this can already change it — roles are per-tenant and
 * their permissions are editable, which is the whole reason the catalogue is central and
 * the roles are not.
 */
final class ShopSettingsPolicy
{
    /**
     * Read the shop's settings screens.
     *
     * Reads are never blocked by anything but this permission — a shop at its plan
     * ceiling still gets to look at what it configured (golden rule 7).
     */
    public function view(User $actor): bool
    {
        return $actor->can('settings.view');
    }

    public function update(User $actor): bool
    {
        return $actor->can('settings.update');
    }
}
