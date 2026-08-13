<?php

declare(strict_types=1);

namespace App\Modules\Repairs\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Repairs\Models\RepairTicket;
use App\Modules\Repairs\Services\QuoteApproval;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The customer approving a quote from their phone.
 *
 * Same non-enumerable treatment as the tracking page: `/a/{48 chars}`, no id in the
 * path, every wrong token an identical 404, hard rate limit. The difference is what the
 * token is worth — tracking reveals a status, this one commits somebody to spending
 * money — so it is minted per request, sent to the number on the ticket rather than
 * printed on a receipt, and cleared the moment it is used.
 *
 * ## The amount shown is the amount frozen when the link was minted
 *
 * Never the current estimate. See {@see QuoteApproval} — the whole point is that what
 * the customer approves is what the customer was shown.
 */
final class PublicApprovalController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        $ticket = $this->find($token);

        return Inertia::render('Repairs::Tracking/Approve', [
            'ticket' => [
                'code' => $ticket->code,
                'device' => trim("{$ticket->device_brand} {$ticket->device_model}"),
                'reported_issue' => $ticket->reported_issue,
                // The frozen figure, not `estimate_amount`.
                'quoted_amount' => Money::toArray($ticket->approval_quoted_amount ?? 0),
                'requested_at' => $ticket->approval_requested_at?->toIso8601String(),
            ],
            'shop' => [
                'name' => $ticket->branch->name,
                'phone' => $ticket->branch->phone,
            ],
            'token' => $token,
        ]);
    }

    public function approve(Request $request, string $token, QuoteApproval $approvals): RedirectResponse
    {
        $ticket = $this->find($token);

        $approvals->approveByLink($ticket);

        // Redirected to tracking rather than re-rendering: the approval token is now
        // spent, so a refresh of this page would 404 and read as "something went wrong"
        // to somebody who just successfully approved.
        return redirect()->route('repairs.tracking', ['token' => $ticket->tracking_token]);
    }

    public function decline(Request $request, string $token, QuoteApproval $approvals): RedirectResponse
    {
        $ticket = $this->find($token);

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        $approvals->decline($ticket, $validated['note'] ?? null);

        return redirect()->route('repairs.tracking', ['token' => $ticket->tracking_token]);
    }

    /**
     * One response for every failure: wrong token, spent token, another shop's token.
     */
    private function find(string $token): RepairTicket
    {
        abort_if(strlen($token) !== 48, 404);

        $ticket = RepairTicket::query()
            ->with('branch:id,name,phone')
            ->where('approval_token', $token)
            ->first();

        abort_if(! $ticket instanceof RepairTicket, 404);

        return $ticket;
    }
}
