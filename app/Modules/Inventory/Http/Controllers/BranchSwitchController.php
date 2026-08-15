<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Services\BranchContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Changing which branch the user is looking at.
 *
 * ## A POST, and it redirects back
 *
 * The switcher sits in the app chrome, so it is pressed from any screen in the product.
 * Redirecting back re-renders whatever the user was reading, now filtered — which is what
 * they asked for. Sending them to a fixed landing page would make the control feel like
 * navigation rather than a filter, and nobody would use it from the middle of a stock list.
 *
 * ## A refusal is a message, never a silent no-op
 *
 * `BranchContext::set()` returns false for a branch the user may not use — a stale tab, a
 * hand-edited request, or an assignment narrowed while they were logged in. Reporting that
 * as an error matters because the alternative is a control that visibly does nothing, which
 * a shopkeeper reads as the software being broken rather than as a permission boundary.
 */
final class BranchSwitchController extends Controller
{
    public function __invoke(Request $request, BranchContext $context): RedirectResponse
    {
        $validated = $request->validate([
            // Nullable IS the consolidated choice — «همه شعب» posts no id.
            'branch_id' => ['nullable', 'integer'],
        ]);

        $branchId = isset($validated['branch_id']) ? (int) $validated['branch_id'] : null;

        if (! $context->set($branchId)) {
            return back()->with('error', 'دسترسی شما به این شعبه وجود ندارد.');
        }

        return back();
    }
}
