<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Reporting\Services\ShopSetupProgress;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * «بعداً» on the first morning's checklist.
 *
 * The dismissal is written to the shop's own settings document, not to the browser:
 * the owner who closes the card on the counter PC must not meet it again on their
 * phone, and the manager they invite tomorrow must not meet it at all. It is the one
 * key the product writes into `tenants.settings` so far; the shop settings screen that
 * will edit the rest does not exist yet, which is why there is no way back in from the
 * interface — deliberately unclaimed in the flash.
 */
final class SetupChecklistController extends Controller
{
    public function __invoke(Request $request, TenantContext $context): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->can(ShopSetupProgress::PERMISSION), 403);

        $tenant = $context->tenant();

        abort_if($tenant === null, 404);

        // Nested by hand rather than through `data_set()`, which hands Larastan back
        // `mixed` for a property typed `array<string, mixed>`. Same shape either way:
        // `setup.dismissed_at`, read back with `Tenant::setting()`'s dot notation.
        $settings = $tenant->settings;
        $setup = is_array($settings['setup'] ?? null) ? $settings['setup'] : [];
        $setup['dismissed_at'] = now()->toIso8601String();
        $settings['setup'] = $setup;

        $tenant->settings = $settings;
        $tenant->save();

        return back()->with('info', 'چک‌لیست راه‌اندازی پنهان شد.');
    }
}
