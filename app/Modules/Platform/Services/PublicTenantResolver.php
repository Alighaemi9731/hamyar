<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Models\Tenant;
use App\Modules\Repairs\Models\RepairTicket;
use App\Modules\Storefront\Models\PriceListLink;
use App\Modules\Storefront\Models\StorefrontSetting;

/**
 * Finds the shop behind a public, unguessable identifier.
 *
 * ## Why it exists
 *
 * [ADR 0017](../../../../docs/adr/0017-single-host-app.md) put every shop on one
 * address. The pages a *customer* reaches — the QR on a repair receipt, a quote approval
 * link, a reseller's price list, a shop's public window — used to be told which shop
 * they belonged to by the hostname. Nothing tells them now except the identifier in the
 * URL, so it has to do the job.
 *
 * ## Why it lives in Platform
 *
 * Every lookup here is `withoutTenancy()`, which golden rule 1 permits only inside this
 * module and only with the reason written down. The reason is structural rather than
 * convenient: these requests have no session and no tenant, so a tenant-scoped query
 * would be evaluated with nothing pinned, RLS would deny it, and the customer would get
 * a 404 for a ticket that exists.
 *
 * ## The surfaces are a fixed whitelist, not a parameter
 *
 * A middleware that took a table and column from the route definition would be one typo
 * away from resolving a tenant off any column an attacker could influence. These four
 * are named here, each pointing at an index that is globally unique **by design** and
 * listed as such in `TenancyCheckCommand::GLOBALLY_UNIQUE_BY_DESIGN`. Adding a fifth
 * means adding it there too, and that check fails the build otherwise.
 */
final class PublicTenantResolver
{
    public function resolve(string $surface, string $value): ?Tenant
    {
        // An empty value would otherwise match any row whose column is '' — the shape of
        // bug where a missing route parameter quietly resolves to somebody's shop.
        if ($value === '') {
            return null;
        }

        /*
        | An explicit branch per surface rather than a table/column pair looked up from a
        | map.
        |
        | A resolver that took a model and a column as data would be one typo in a route
        | definition away from resolving a tenant off a column an attacker can influence.
        | Written out, each line names a column whose index is globally unique BY DESIGN
        | and listed as such in TenancyCheckCommand::GLOBALLY_UNIQUE_BY_DESIGN — and
        | that check fails the build if a global unique index appears without an entry,
        | which is what stops this becoming a way to smuggle one in.
        */
        $tenantId = match ($surface) {
            // The QR printed on the intake receipt.
            'repair-tracking' => RepairTicket::withoutTenancy()
                ->where('tracking_token', $value)->value('tenant_id'),
            // The link that asks a customer to approve a quote.
            'repair-approval' => RepairTicket::withoutTenancy()
                ->where('approval_token', $value)->value('tenant_id'),
            // A reseller price list, opened by somebody with the link and a passphrase.
            'price-list' => PriceListLink::withoutTenancy()
                ->where('lookup', $value)->value('tenant_id'),
            // A shop's public window. A slug rather than a secret — it is meant to be
            // shareable — which is why the page behind it shows prices and nothing else.
            'storefront' => StorefrontSetting::withoutTenancy()
                ->where('slug', $value)->value('tenant_id'),
            default => null,
        };

        if (! is_int($tenantId)) {
            return null;
        }

        return Tenant::query()->find($tenantId);
    }
}
