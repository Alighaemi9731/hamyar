<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Reporting\Http\Requests\SavedFilterRequest;
use App\Modules\Reporting\Models\SavedFilter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Saving and forgetting a named filter set.
 *
 * ## Saving the same name twice updates it
 *
 * `updateOrCreate` on (user, report, name), matching the unique index. A shopkeeper who
 * saves «سه ماه گذشته» after adjusting the range means *update that*, and the alternative —
 * a validation error telling them the name is taken — asks them to delete a preset in order
 * to change it.
 *
 * ## Deleting is scoped to the owner, not just to the tenant
 *
 * RLS already makes another shop's preset invisible. Within one shop, presets are personal
 * (see the migration), so the query also filters by `user_id` and a colleague's preset comes
 * back 404 rather than 403 — the same answer as one that never existed, because from this
 * user's side that is exactly what it is.
 */
final class SavedFilterController extends Controller
{
    public function store(SavedFilterRequest $request): RedirectResponse
    {
        $user = $this->viewer($request);

        /** @var array<string, string> $filters */
        $filters = $request->validated('filters') ?? [];

        $name = $request->validated('name');
        $reportKey = $request->validated('report_key');

        SavedFilter::query()->updateOrCreate(
            [
                'user_id' => $user->getKey(),
                'report_key' => is_string($reportKey) ? $reportKey : '',
                'name' => is_string($name) ? trim($name) : '',
            ],
            ['filters' => $filters],
        );

        return back();
    }

    public function destroy(Request $request, SavedFilter $savedFilter): RedirectResponse
    {
        $user = $this->viewer($request);

        abort_unless($savedFilter->user_id === $user->getKey(), 404);

        $savedFilter->delete();

        return back();
    }

    private function viewer(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->can('reporting.view'), 403);

        return $user;
    }
}
