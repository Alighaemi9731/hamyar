<?php

declare(strict_types=1);

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\Party;
use App\Modules\CRM\Services\LoyaltyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Hand adjustments to a party's loyalty points.
 *
 * Earning is automatic and belongs to the sale that caused it (Phase 5); what is here
 * is the two things a person does — correcting a mistake and spending points at the
 * counter. Both require a description, because an unexplained points entry is the one
 * a customer will definitely ask about.
 */
final class LoyaltyController extends Controller
{
    public function store(Request $request, Party $party, LoyaltyService $loyalty): RedirectResponse
    {
        // Not `update`: a Salesperson may correct a phone number and must not be able
        // to grant something worth money.
        $this->authorize('manageLoyalty', Party::class);

        $validated = $request->validate([
            'points' => ['required', 'integer', 'min:-100000', 'max:100000', 'not_in:0'],
            'description' => ['required', 'string', 'max:200'],
        ], [
            'points.not_in' => 'تعداد امتیاز نمی‌تواند صفر باشد.',
            'description.required' => 'دلیل این تغییر را بنویسید.',
        ]);

        try {
            $points = (int) $validated['points'];

            if ($points < 0) {
                // Routed through `redeem` so the overdraw guard applies: points are not
                // credit, and there is nothing to collect from a negative balance.
                $loyalty->redeem($party->id, -$points, $validated['description'], $request->user()?->id);
            } else {
                $loyalty->adjust($party->id, $points, $validated['description'], $request->user()?->id);
            }
        } catch (RuntimeException $exception) {
            return back()->withErrors(['points' => 'امتیاز کافی برای این کسر وجود ندارد.']);
        }

        return back()->with('success', 'امتیاز به‌روزرسانی شد.');
    }
}
