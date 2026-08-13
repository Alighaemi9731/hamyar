<?php

declare(strict_types=1);

namespace App\Modules\Repairs\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Repairs\Models\RepairTicket;
use App\Modules\Repairs\Models\TicketPart;
use App\Modules\Repairs\Services\PartLookup;
use App\Modules\Repairs\Services\TicketParts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Parts on a job, from the bench.
 *
 * The three verbs are the three states, and each is its own route because each is its own
 * decision by a person: planning a part is not fitting it, and fitting it is not the same
 * as giving it back.
 *
 * ## Why fitting is a separate press
 *
 * It is tempting to consume on the transition to `ready` and save the technician a click.
 * That would be wrong on the jobs that matter: a repair often plans two possible fixes
 * and fits one, and a ticket that consumed both would take a screen off the shelf that is
 * still in the drawer. The shop finds out at the next stock count.
 *
 * ## Failures come back as a message on the page
 *
 * Every guard in {@see TicketParts} throws with a sentence a shopkeeper can act on —
 * "there are only two left", "this ticket is closed". Those belong beside the parts
 * panel, not on a 500 page, so they are caught and flashed as a general error.
 */
final class TicketPartsController extends Controller
{
    /**
     * Parts matching what the technician is typing.
     */
    public function search(Request $request, RepairTicket $ticket, PartLookup $lookup): JsonResponse
    {
        $this->authorize('update', $ticket);

        return response()->json([
            'results' => $lookup->search($request->string('q')->value(), $ticket->branch_id),
        ]);
    }

    /**
     * Plan a part into the job, holding the stock.
     */
    public function store(Request $request, RepairTicket $ticket, TicketParts $parts, PartLookup $lookup): RedirectResponse
    {
        $this->authorize('update', $ticket);

        $data = $request->validate([
            'variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'unit_price' => ['nullable', 'integer', 'min:0'],
        ]);

        $warehouseId = $lookup->warehouseIdFor($ticket->branch_id);

        if ($warehouseId === null) {
            return back()->withErrors(['parts' => 'برای این شعبه انباری تعریف نشده است.']);
        }

        try {
            $parts->reserve(
                $ticket,
                (int) $data['variant_id'],
                $warehouseId,
                (int) $data['quantity'],
                (int) ($data['unit_price'] ?? 0),
                $request->user()?->id,
            );
        } catch (RuntimeException $exception) {
            return back()->withErrors(['parts' => $exception->getMessage()]);
        }

        return back()->with('status', 'قطعه برای این تعمیر رزرو شد.');
    }

    /**
     * The part was fitted. Stock leaves the shelf here, and only here.
     */
    public function consume(Request $request, RepairTicket $ticket, TicketPart $part, TicketParts $parts): RedirectResponse
    {
        $this->authorize('update', $ticket);

        $this->guardBelongsTo($ticket, $part);

        try {
            $parts->consume($part, $request->user()?->id);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['parts' => $exception->getMessage()]);
        }

        return back()->with('status', 'قطعه مصرف شد و از موجودی کسر گردید.');
    }

    /**
     * Not needed after all. The hold comes off and the till may sell it again.
     */
    public function destroy(Request $request, RepairTicket $ticket, TicketPart $part, TicketParts $parts): RedirectResponse
    {
        $this->authorize('update', $ticket);

        $this->guardBelongsTo($ticket, $part);

        try {
            $parts->release($part, $request->user()?->id);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['parts' => $exception->getMessage()]);
        }

        return back()->with('status', 'رزرو قطعه آزاد شد.');
    }

    /**
     * The part must be on the ticket in the URL.
     *
     * Tenancy already stops another shop's part being addressed at all. This stops one of
     * our own tickets being used to consume a part planned for a different job — the
     * nested route reads as though it were checked, and nothing else checks it.
     */
    private function guardBelongsTo(RepairTicket $ticket, TicketPart $part): void
    {
        abort_unless($part->repair_ticket_id === $ticket->getKey(), 404);
    }
}
