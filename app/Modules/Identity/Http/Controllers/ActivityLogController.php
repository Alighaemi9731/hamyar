<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\Activity;
use App\Modules\Identity\Models\User;
use App\Support\Jalali;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Who changed what, and when."
 *
 * Reads only. An audit trail an operator can edit is not an audit trail, so there is
 * deliberately no update or delete route — entries age out on a retention schedule
 * instead.
 */
final class ActivityLogController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Activity::class);

        $query = Activity::query()->with('causer:id,name')->latest();

        if ($request->filled('user')) {
            $query->where('causer_type', User::class)->where('causer_id', $request->integer('user'));
        }

        if ($request->filled('subject_type')) {
            $query->where('subject_type', $request->string('subject_type')->value());
        }

        // Jalali range in, UTC bounds out — a report using the wrong bound loses or
        // duplicates the last evening's entries (App\Support\Jalali).
        if ($request->filled('from')) {
            $query->where('created_at', '>=', Jalali::startOfDay($request->string('from')->value()));
        }

        if ($request->filled('to')) {
            $query->where('created_at', '<=', Jalali::endOfDay($request->string('to')->value()));
        }

        $activities = $query->paginate(self::PER_PAGE)->withQueryString();

        return Inertia::render('settings/activity', [
            'activities' => [
                'data' => collect($activities->items())->map(fn (Activity $activity): array => [
                    'id' => $activity->getKey(),
                    'description' => $activity->description,
                    'event' => $activity->getAttribute('event'),
                    'subject_type' => $activity->subject_type !== null ? class_basename($activity->subject_type) : null,
                    'subject_id' => $activity->subject_id,
                    'causer' => $activity->causer?->getAttribute('name'),
                    'created_at' => $activity->created_at?->toIso8601String(),
                    // v5 exposes the before/after payload as `properties`; there is no
                    // changes() helper. `attributes` is the new state, `old` the previous.
                    'changes' => [
                        'attributes' => $activity->getProperty('attributes', []),
                        'old' => $activity->getProperty('old', []),
                    ],
                ])->values()->all(),
                'current_page' => $activities->currentPage(),
                'last_page' => $activities->lastPage(),
                'total' => $activities->total(),
            ],
            'filters' => $request->only(['user', 'subject_type', 'from', 'to']),
            'users' => User::query()->orderBy('name')->get(['id', 'name'])->all(),
        ]);
    }
}
