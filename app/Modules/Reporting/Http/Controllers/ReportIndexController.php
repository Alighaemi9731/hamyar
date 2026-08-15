<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Reporting\Services\ReportCatalogue;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The report index — every report the shop can run, filed the way it thinks about them.
 *
 * A flat alphabetical list of thirty reports is a list nobody reads twice; the grouping
 * is what makes «کدوم گزارش سود رو نشون می‌ده» answerable by looking rather than by
 * asking.
 */
final class ReportIndexController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user() instanceof User ? $request->user() : null;

        return Inertia::render('Reporting::Reports/Index', [
            'groups' => ReportCatalogue::visibleTo($user),
        ]);
    }
}
