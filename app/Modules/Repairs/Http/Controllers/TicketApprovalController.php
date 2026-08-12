<?php

declare(strict_types=1);

namespace App\Modules\Repairs\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Repairs\Models\RepairTicket;
use App\Modules\Repairs\Services\QuoteApproval;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The shop's half of the approval conversation.
 *
 * {@see PublicApprovalController} is the customer tapping a link. This is the side that
 * creates the question in the first place — and it was missing. The service had been
 * green since the approval commit with no route at all, which meant a shopkeeper could
 * not quote a job, could not produce a link to send, and could not write down a yes given
 * over the phone. The public page existed to answer a question nothing could ask.
 *
 * ## Quoting is a write, not a read
 *
 * `request()` freezes the figure, mints a new token and invalidates any link already
 * sent. It is a POST for that reason: re-quoting is a real change to what the customer
 * is being asked, not a page refresh.
 *
 * ## Phone approval is separate from link approval, on purpose
 *
 * Most Iranian shops settle this by phone — the customer rings back and says go ahead.
 * Recording that through the same door as a link tap would lose the distinction, and the
 * distinction is the whole audit trail: `approved_via` is what answers "how do we know
 * they agreed" three weeks later.
 */
final class TicketApprovalController extends Controller
{
    /**
     * Quote the job and mint the link.
     */
    public function request(Request $request, RepairTicket $ticket, QuoteApproval $approvals): RedirectResponse
    {
        $this->authorize('approve', $ticket);

        $data = $request->validate([
            'quoted_amount' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $approvals->request($ticket, (int) $data['quoted_amount'], $request->user()?->id);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['approval' => $exception->getMessage()]);
        }

        return back()->with('status', 'لینک تأیید ساخته شد؛ برای مشتری بفرستید.');
    }

    /**
     * The customer said yes on the phone.
     */
    public function approve(Request $request, RepairTicket $ticket, QuoteApproval $approvals): RedirectResponse
    {
        $this->authorize('approve', $ticket);

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $actorId = $request->user()?->id;

        if ($actorId === null) {
            return back()->withErrors(['approval' => 'برای ثبت تأیید تلفنی باید وارد شده باشید.']);
        }

        try {
            $approvals->approveByPhone($ticket, $actorId, $data['note'] ?? null);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['approval' => $exception->getMessage()]);
        }

        return back()->with('status', 'تأیید تلفنی مشتری ثبت شد.');
    }

    /**
     * The customer said no.
     */
    public function decline(Request $request, RepairTicket $ticket, QuoteApproval $approvals): RedirectResponse
    {
        $this->authorize('approve', $ticket);

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $approvals->decline($ticket, $data['note'] ?? null);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['approval' => $exception->getMessage()]);
        }

        return back()->with('status', 'عدم تأیید مشتری ثبت شد.');
    }
}
