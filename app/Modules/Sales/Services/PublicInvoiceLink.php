<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\Platform\Models\Domain;
use App\Modules\Sales\Models\SalesInvoice;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\URL;

/**
 * The address printed as a QR code on a customer's receipt.
 *
 * ## Signed, and deliberately never expiring
 *
 * The signature is what stops somebody walking `/i/1`, `/i/2` and reading every invoice
 * the shop ever issued. It is not a session: a customer photographs their receipt and
 * opens it eight months later to check a warranty date, and a link that has quietly
 * expired is worse than no QR at all, because they will ring the shop about it.
 *
 * Expiry would also be pointless here. The receipt itself does not expire, and anybody
 * holding the paper already has everything the page shows.
 *
 * ## The hostname comes from the shop's own `domains` row
 *
 * Never assembled from a literal apex (golden rule 1b). The production domain is not
 * chosen yet, and a receipt is the worst possible place to bake one in — it is printed
 * on paper that outlives the deploy that changes it.
 */
final class PublicInvoiceLink
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * The absolute, signed URL for this invoice, or null when there is nowhere to point.
     *
     * Null rather than a throw: a shop whose domain row is missing has a broken
     * installation, and the receipt should still print — without a QR — rather than the
     * sale failing at the printer.
     */
    public function for(SalesInvoice $invoice): ?string
    {
        $hostname = $this->hostname();

        if ($hostname === null) {
            return null;
        }

        $scheme = str_starts_with(config()->string('app.url'), 'https://') ? 'https' : 'http';

        URL::forceRootUrl("{$scheme}://{$hostname}");

        try {
            return URL::signedRoute('sales.invoices.public', ['invoice' => $invoice->getKey()], absolute: true);
        } finally {
            // Cleared, not restored — see ImpersonationService for why passing a value
            // here pins the generator's root for the rest of the process.
            URL::forceRootUrl(null);
        }
    }

    /**
     * The shop's primary hostname.
     */
    private function hostname(): ?string
    {
        $tenantId = $this->context->id();

        if ($tenantId === null) {
            return null;
        }

        $hostname = Domain::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('is_primary')
            ->value('hostname');

        return is_string($hostname) && $hostname !== '' ? $hostname : null;
    }
}
