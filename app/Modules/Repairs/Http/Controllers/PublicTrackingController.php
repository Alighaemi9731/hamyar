<?php

declare(strict_types=1);

namespace App\Modules\Repairs\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Repairs\Models\RepairTicket;
use App\Modules\Repairs\Models\TicketStatusHistory;
use App\Support\Money;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What a customer sees when they scan the QR on their repair receipt.
 *
 * ## The threat here is worse than on the public invoice
 *
 * An invoice tells a stranger what somebody bought. **A repair status tells them a named
 * person's device is out of their hands right now** — which is information a burglar can
 * use, and information an abusive ex can use. Everything below is stricter than the
 * invoice page for that reason, not out of symmetry.
 *
 * ## There is nothing to enumerate
 *
 * The URL is `/t/{token}` with 48 characters of CSPRNG and **no id anywhere in it**.
 * That is a deliberate correction of the invoice page, where `/i/{id}?signature=…`
 * created an oracle: a bare id answered "has this shop issued 4,000 invoices yet?"
 * through the 403-vs-404 difference. Here every wrong token is an identical 404 and
 * there is no second response to compare it against.
 *
 * The lookup is on `tracking_token` and never on `code`. `REP-000001` is printed on the
 * paper and read out over the phone precisely because it is short and human — which is
 * exactly what makes it unfit to be a credential.
 *
 * ## What this page will not say
 *
 * - **No other ticket, ever.** Not "your other repair", not a count.
 * - **No customer identity.** The person holding the paper knows who they are; a page
 *   that prints a name and a phone number is one that does so for whoever finds it.
 * - **No IMEI or serial.** It is on the paper in their hand, and it is what a
 *   stolen-device registry check keys on.
 * - **No passcode, and no hint one exists.**
 * - **No technician name, no internal notes, no cost breakdown, no parts.** The history
 *   is rendered as customer-facing status labels and dates — never the staff notes
 *   attached to them, which say things like "customer is difficult, quoted high".
 * - **No shop-internal figures.** The amount shown is what this customer owes on this
 *   job, and nothing about what it cost the shop.
 */
final class PublicTrackingController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        // Length-checked before the query so a garbage URL costs a string comparison
        // rather than a database round trip — this endpoint is unauthenticated and
        // therefore the cheapest thing on the site to point a script at.
        abort_if(strlen($token) !== 48, 404);

        $ticket = RepairTicket::query()
            ->with(['branch:id,name,address,phone', 'histories'])
            ->where('tracking_token', $token)
            ->first();

        // One response for every failure: wrong token, deleted ticket, another shop's
        // token on this hostname. Nothing distinguishable to compare.
        abort_if(! $ticket instanceof RepairTicket, 404);

        return Inertia::render('Repairs::Tracking/Show', [
            'ticket' => [
                'code' => $ticket->code,
                'status' => $ticket->status->value,
                'status_label' => $ticket->status->labelFa(),
                'is_ready' => $ticket->status === \App\Modules\Repairs\Enums\TicketStatus::Ready,
                // The device as the customer described it, without the serial that
                // identifies it to anybody else.
                'device' => trim("{$ticket->device_brand} {$ticket->device_model}"),
                'received_at' => $ticket->created_at?->toIso8601String(),
                'promised_at' => $ticket->promised_at?->toIso8601String(),
                'ready_at' => $ticket->ready_at?->toIso8601String(),
                // What they owe on this job. Approved beats estimate: once the customer
                // has agreed a figure, showing them the old guess invites an argument.
                'amount_due' => Money::toArray(
                    max(0, ($ticket->approved_amount ?? $ticket->estimate_amount) - $ticket->prepaid_amount)
                ),
                'is_estimate' => $ticket->approved_amount === null,
                /*
                | Dates and status labels only. The `note` column is deliberately absent:
                | staff write things in there like "customer is difficult, quoted high",
                | and a timeline that renders internal notes to the public is a page that
                | will eventually end a customer relationship.
                */
                'timeline' => $ticket->histories
                    ->sortBy('id')
                    ->map(fn (TicketStatusHistory $h): array => [
                        'id' => $h->id,
                        'label' => $h->to_status->labelFa(),
                        'at' => $h->created_at?->toIso8601String(),
                    ])
                    ->values()
                    ->all(),
            ],
            'shop' => [
                'name' => $ticket->branch->name,
                'address' => $ticket->branch->address,
                'phone' => $ticket->branch->phone,
            ],
        ]);
    }
}
