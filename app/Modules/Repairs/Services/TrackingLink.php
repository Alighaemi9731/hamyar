<?php

declare(strict_types=1);

namespace App\Modules\Repairs\Services;

use App\Modules\Platform\Models\Domain;
use App\Modules\Repairs\Models\RepairTicket;
use App\Support\Tenancy\TenantContext;

/**
 * The address on the customer's receipt.
 *
 * ## The token IS the access control, and there is no id in the URL
 *
 * `/t/{token}`, where the token is 48 characters from a CSPRNG. Deliberately different
 * from the public invoice link, which is `/i/{id}?signature=…`, and the difference is
 * the lesson that page taught.
 *
 * On the invoice page the id in the path created an oracle: a bare `/i/4000` answered
 * "has this shop issued four thousand invoices yet?" through the difference between 403
 * and 404, before any signature was checked. That was fixed by moving the lookup behind
 * the signature — but the shape of the URL still invites the question.
 *
 * Here there is no question to ask. The URL contains nothing sequential, nothing
 * guessable and nothing about the shop's volume. A wrong token is a 404 and a right one
 * is the only way in, so there is no pair of responses to compare.
 *
 * That matters more here than it did there. An invoice reveals what somebody bought; a
 * repair status reveals that a named person's device is **out of their hands right now**,
 * which is information a burglar can use.
 *
 * ## No expiry, again
 *
 * A repair takes weeks and the paper survives it. A link that expires is one the customer
 * rings the shop about — which is the phone call the tracking page exists to prevent.
 *
 * ## The hostname comes from the shop's own `domains` row
 *
 * Never assembled from a literal apex (golden rule 1b). This one is printed on thermal
 * paper that outlives any deploy that would change it.
 */
final class TrackingLink
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * The absolute tracking URL, or null when there is nowhere to point.
     *
     * Null rather than a throw: a shop with a missing domain row has a broken
     * installation, and the receipt should still print — without a QR — rather than the
     * intake failing at the printer with a customer watching.
     */
    public function for(RepairTicket $ticket): ?string
    {
        $hostname = $this->hostname();

        if ($hostname === null) {
            return null;
        }

        $scheme = str_starts_with(config()->string('app.url'), 'https://') ? 'https' : 'http';

        return "{$scheme}://{$hostname}/t/{$ticket->tracking_token}";
    }

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
