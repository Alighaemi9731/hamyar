<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Models\Module;
use App\Modules\Platform\Models\Plan;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;

/**
 * The public landing page.
 *
 * Blade rather than Inertia, deliberately: this is the one page that is read by people
 * who have never signed in and by crawlers that do not run JavaScript, so its content
 * has to exist in the HTML the server sends.
 *
 * ## Prices come from the database, never from the template
 *
 * The plan catalogue is editable in the Filament panel, and roadmap 11.4 says plainly
 * that changing a price at launch "is a panel edit, not a deploy". A price typed into
 * this Blade file would quietly break that promise: the panel would change, the billing
 * screen would change, and the landing page — the only one a prospect actually reads —
 * would keep advertising the old number until somebody noticed.
 *
 * So the figures below are read from `plans` and `modules`, cached briefly. The cache
 * is short and global on purpose: this page has no tenant, so there is no tenant to key
 * it by (ADR 0012 asks every stateful singleton to say why its key is what it is), and
 * a price edit reaching the public page within a minute is the right trade for not
 * running two queries on every anonymous hit.
 */
final class LandingController
{
    private const CACHE_TTL = 60;

    public function __invoke(): View
    {
        /** @var array{plans: \Illuminate\Support\Collection<int, Plan>, addons: \Illuminate\Support\Collection<int, Module>} $catalogue */
        $catalogue = Cache::remember('landing.catalogue', self::CACHE_TTL, fn (): array => [
            'plans' => Plan::query()
                ->where('is_public', true)
                ->with('modules:id,code,name_fa')
                ->orderBy('position')
                ->get(),
            'addons' => Module::query()
                ->where('is_addonable', true)
                ->where('addon_price', '>', 0)
                ->orderBy('addon_price')
                ->get(['id', 'code', 'name_fa', 'addon_price']),
        ]);

        return view('landing', $catalogue);
    }
}
