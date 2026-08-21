<?php

declare(strict_types=1);

namespace App\Modules\Repairs\Services;

use App\Modules\Platform\Models\Domain;
use App\Modules\Repairs\Models\RepairTicket;

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

    /**
     * The absolute approval URL, or null when there is no live question to answer.
     *
     * Null once the token has been used or was never minted — the panel then shows the
     * recorded answer instead of a dead link, which is the honest thing to put in front
     * of somebody about to text it to a customer.
     *
     * Shares {@see hostname()} with tracking deliberately. There is exactly one place
     * that decides what host a customer-facing link points at, and it reads a `domains`
     * row rather than a literal — golden rule 1b, which is the difference between
     * changing the apex domain by configuration and changing it by grep.
     */
    public function approvalFor(RepairTicket $ticket): ?string
    {
        if (! is_string($ticket->approval_token) || $ticket->approval_token === '') {
            return null;
        }

        $hostname = $this->hostname();

        if ($hostname === null) {
            return null;
        }

        $scheme = str_starts_with(config()->string('app.url'), 'https://') ? 'https' : 'http';

        return "{$scheme}://{$hostname}/a/{$ticket->approval_token}";
    }

    /**
     * The application's host — one address for every shop.
     *
     * This used to read `domains.hostname`, because the QR pointed at the shop's own
     * subdomain and that hostname was what told the tracking page which shop the ticket
     * belonged to. ADR 0017 removed per-shop addresses, and the token now identifies the
     * ticket by itself (it is globally unique for exactly this reason), so every link
     * points here.
     *
     * Built from `config('app.domain')`, never a literal — golden rule 1b, which is what
     * keeps changing the apex a configuration change rather than a grep.
     */
    private function hostname(): ?string
    {
        $domain = config()->string('app.domain');

        return $domain !== '' ? 'app.'.$domain : null;
    }
}
