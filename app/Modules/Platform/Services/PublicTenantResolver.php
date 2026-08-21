<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Modules\Identity\Models\Invitation;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Repairs\Models\RepairTicket;
use App\Modules\Storefront\Models\PriceListLink;
use App\Modules\Storefront\Models\StorefrontSetting;
use App\Modules\Storefront\Services\PriceListAccess;
use App\Support\Tenancy\TenantContext;

/**
 * Finds the shop behind a public, unguessable identifier.
 *
 * ## Why it exists
 *
 * [ADR 0017](../../../../docs/adr/0017-single-host-app.md) put every shop on one
 * address. The pages a *customer* reaches — the QR on a repair receipt, a quote approval
 * link, a reseller's price list, a shop's public window, the invite a new colleague was
 * sent — used to be told which shop they belonged to by the hostname. Nothing tells them
 * now except the identifier in the URL, so it has to do the job.
 *
 * ## Why it lives in Platform
 *
 * Every lookup here crosses tenants, which golden rule 1 permits only inside this module
 * and only with the reason written down. The reason is structural rather than convenient:
 * these requests have no session and no tenant, so a tenant-scoped query would be
 * evaluated with nothing pinned, RLS would deny it, and the customer would get a 404 for
 * a ticket that exists.
 *
 * ## `withoutTenancy()` alone was not enough, and the symptom was a silent 404
 *
 * ADR 0002 stacks two layers, and `withoutTenancy()` lifts only the second: it removes
 * the Eloquent global scope. Layer 1, the Postgres policy, still evaluates
 * `tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint` — and with no
 * context pinned that is never true, so **every one of these lookups returned nothing**
 * and every public token page 404'd unconditionally. It fails closed, which is the right
 * posture and the reason nothing exploded; it also means the bug looked exactly like a
 * wrong token.
 *
 * This is the same defect commit 2e2951c fixed for `users`/`AccountLookup`, which is why
 * that class already wraps its lookup in `TenantContext::runAsPlatform()` and why this
 * one now does too. `runAsPlatform()` sets `app.platform`, which only the policies of
 * tables that opted in with `enableRls(..., allowPlatform: true)` consult — so this is
 * not a blanket bypass, it is the narrow, greppable escape of ADR 0002's amendment, and
 * every table named below opts in explicitly in its own migration.
 *
 * ## The surfaces are a fixed whitelist, not a parameter
 *
 * A middleware that took a table and column from the route definition would be one typo
 * away from resolving a tenant off any column an attacker could influence. These five
 * are named here, each pointing at an index that is globally unique **by design** and
 * listed as such in `TenancyCheckCommand::GLOBALLY_UNIQUE_BY_DESIGN`. Adding a sixth
 * means adding it there too, and that check fails the build otherwise.
 */
final class PublicTenantResolver
{
    public function __construct(private readonly TenantContext $context) {}

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
        |
        | The whole match runs inside runAsPlatform() because withoutTenancy() only lifts
        | the Eloquent scope; without the flag the RLS policy denies every row and each
        | branch returns null. See the class docblock.
        */
        $tenantId = $this->context->runAsPlatform(fn (): mixed => match ($surface) {
            // The QR printed on the intake receipt.
            'repair-tracking' => RepairTicket::withoutTenancy()
                ->where('tracking_token', $value)->value('tenant_id'),
            // The link that asks a customer to approve a quote.
            'repair-approval' => RepairTicket::withoutTenancy()
                ->where('approval_token', $value)->value('tenant_id'),
            // A reseller price list, opened by somebody with the link and a passphrase.
            'price-list' => $this->priceListTenantId($value),
            // A shop's public window. A slug rather than a secret — it is meant to be
            // shareable — which is why the page behind it shows prices and nothing else.
            'storefront' => StorefrontSetting::withoutTenancy()
                ->where('slug', $value)->value('tenant_id'),
            /*
            | An invite, opened by somebody who has no account yet — so there is nobody
            | to read a tenant from. Only the hash is stored, and only the hash is
            | compared: the raw token never touches a query or a log.
            */
            'invitation' => Invitation::withoutTenancy()
                ->where('token_hash', hash('sha256', $value))->value('tenant_id'),
            default => null,
        });

        if (! is_int($tenantId)) {
            return null;
        }

        // `tenants` is central, not tenant-scoped, so this one needs no escape.
        return Tenant::query()->find($tenantId);
    }

    /**
     * The tenant behind a price-list token.
     *
     * `price_list_links.lookup` holds only the FIRST `PriceListAccess::LOOKUP_LENGTH`
     * characters of the token — the non-secret prefix that lets one indexed query find
     * the row, with the secret half kept as a hash. Matching the whole 44-character
     * token against that column can therefore never hit, which is a miss that looks
     * precisely like a wrong token. `PriceListAccess::resolve()` splits it the same way
     * and then verifies the secret half; this only has to name the shop.
     */
    private function priceListTenantId(string $value): mixed
    {
        if (strlen($value) < PriceListAccess::LOOKUP_LENGTH) {
            return null;
        }

        return PriceListLink::withoutTenancy()
            ->where('lookup', substr($value, 0, PriceListAccess::LOOKUP_LENGTH))
            ->value('tenant_id');
    }
}
