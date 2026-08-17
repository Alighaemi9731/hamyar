<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Storefront\Models\StorefrontSetting;
use App\Modules\Storefront\Services\PublicCatalogue;
use Illuminate\View\View;

/**
 * The shop's public page: who they are, what they have, and how to phone them.
 *
 * ## It sells the phone call, not the basket
 *
 * Gate 4, part 1: no cart, no checkout, no accounts. Every action on this page is a way to
 * start a conversation — a tel: link, a WhatsApp link, an address. That is what an Iranian
 * phone shop's website is for, and building a checkout nobody asked for would be a quarter
 * of a phase spent on the thing this product explicitly does not do.
 *
 * ## Disabled is a 404, not a placeholder
 *
 * A shop that has not switched its storefront on has no public page — not an empty one with
 * their name on it. The slug is guessable, and a half-configured page indexed by a search
 * engine is worse for the shop than no page at all.
 */
final class PublicShopController extends Controller
{
    public function show(PublicCatalogue $catalogue): View
    {
        $settings = StorefrontSetting::query()->first();

        abort_unless($settings instanceof StorefrontSetting && $settings->is_enabled, 404);

        return view('storefront::shop', [
            'settings' => $settings,
            'rows' => $catalogue->forPublic($settings),
        ]);
    }
}
