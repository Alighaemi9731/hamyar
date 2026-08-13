<?php

declare(strict_types=1);

namespace App\Modules\Repairs\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Repairs\Models\RepairTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The one door the device passcode comes out of.
 *
 * ## Why revealing is a request of its own
 *
 * The code is not a field on the ticket page that happens to be blurred. It is never
 * sent to the browser at all until somebody explicitly asks for it, because a value
 * present in an Inertia prop is a value in the page source, in the browser's memory, in
 * a screenshot, and in whatever a support tool captures — masked or not.
 *
 * So the ticket page ships `has_passcode: true` and nothing else, and this endpoint is
 * the only code path that ever decrypts it.
 *
 * ## Every reveal is written down before it is answered
 *
 * The audit row goes in **first**, exactly as impersonation does. If writing the trail
 * fails, the caller gets an error and no secret — never the other way round. The record
 * lands in the shop's own activity log, so an owner can see who looked at a customer's
 * unlock code and when, which is the point: the protection is not that nobody can read
 * it, but that nobody can read it invisibly.
 */
final class PasscodeController extends Controller
{
    public function show(Request $request, RepairTicket $ticket): JsonResponse
    {
        $this->authorize('revealPasscode', $ticket);

        /** @var User $actor */
        $actor = $request->user();

        if (! $ticket->hasPasscode()) {
            // Answered without an audit row: there was no secret to look at, and
            // logging a reveal that revealed nothing would bury the real ones.
            return response()->json(['passcode' => null]);
        }

        // Written BEFORE the value is read. A failure here must cost the caller the
        // secret, not the shop the record.
        activity('repairs')
            ->performedOn($ticket)
            ->causedBy($actor)
            ->withProperties([
                'ticket_code' => $ticket->code,
                'device' => trim("{$ticket->device_brand} {$ticket->device_model}"),
                'ip' => $request->ip(),
            ])
            ->log("رمز دستگاه تیکت {$ticket->code} مشاهده شد");

        return response()->json(['passcode' => $ticket->device_passcode]);
    }
}
