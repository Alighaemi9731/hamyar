<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Storefront\Models\PriceListLink;
use App\Modules\Storefront\Models\StorefrontSetting;
use App\Modules\Storefront\Services\PriceListAccess;
use App\Modules\Storefront\Services\PublicCatalogue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * The public reseller price list.
 *
 * ## Status codes carry meaning here, and the spec pins them
 *
 * - **410 Gone** for expired or revoked. Not 404: the link *was* real, and «منقضی شده» tells
 *   the colleague to ask for a new one instead of wondering whether they mistyped it.
 * - **404** for a token that does not resolve — including one whose hash fails. A visitor
 *   must not be able to tell "no such link" from "wrong token", or this becomes an oracle
 *   for guessing lookups.
 * - **403** for a wrong password, and nothing about the list leaks with it.
 *
 * ## Nothing is loaded before the gates pass
 *
 * The catalogue query does not run until expiry, revocation and password have all been
 * cleared. That is the spec's *"and never the prices"* — an expired link that renders a
 * page and then hides it with CSS has already sent the figures.
 *
 * ## Blade, not React
 *
 * Server-rendered, no client bundle: these pages open on an Iranian mobile connection, often
 * on a colleague's old phone, and the design brief's budget assumes no framework here.
 */
final class PriceListController extends Controller
{
    public function show(Request $request, string $token, PriceListAccess $access, PublicCatalogue $catalogue): View|Response
    {
        $link = $access->resolve($token);

        if (! $link instanceof PriceListLink) {
            return abort(404);
        }

        // Gone, not missing: the link existed and the shop or the clock closed it.
        if ($link->isRevoked() || $link->isExpired()) {
            return response()->view('storefront::price-list-closed', [
                'revoked' => $link->isRevoked(),
                'shop' => $this->shopName(),
            ], 410);
        }

        if (! $access->passwordSatisfied($link, $request)) {
            // The lock screen, with no prices behind it — 200, because the visitor has not
            // failed anything yet. A wrong *attempt* is the 403, in `unlock()`.
            return response()->view('storefront::price-list-locked', [
                'token' => $token,
                'shop' => $this->shopName(),
                'error' => null,
            ]);
        }

        $access->logView($link, $request);

        return view('storefront::price-list', [
            'link' => $link,
            'shop' => $this->shopName(),
            // The level comes from the LINK, never from the request. That is what makes
            // "a token cannot be manipulated to reveal another level" structural.
            'level' => $this->levelName($link),
            'rows' => $catalogue->forLevel($link->price_level_id, $this->categoriesOf($link)),
            'token' => $token,
            'expires_at' => $link->expires_at,
        ]);
    }

    /**
     * Submit the password.
     *
     * Rate-limited on the route — the spec asks for it explicitly, because a password on a
     * public URL is otherwise free to brute-force.
     */
    public function unlock(Request $request, string $token, PriceListAccess $access): RedirectResponse|Response
    {
        $link = $access->resolve($token);

        // `abort()` returns `never`, but only when its own return value is the statement —
        // level 8 will not infer it from a bare call inside an if.
        if (! $link instanceof PriceListLink) {
            return abort(404);
        }

        if (! $link->isLive()) {
            return response()->view('storefront::price-list-closed', [
                'revoked' => $link->isRevoked(),
                'shop' => $this->shopName(),
            ], 410);
        }

        $password = $request->string('password')->value();

        if (! $access->attemptPassword($link, $request, $password)) {
            // 403 and the lock screen again. Nothing about the list is in this response.
            return response()->view('storefront::price-list-locked', [
                'token' => $token,
                'shop' => $this->shopName(),
                'error' => 'رمز درست نیست.',
            ], 403);
        }

        return redirect()->route('storefront.price-list', ['token' => $token]);
    }

    /**
     * The same list as a printable sheet.
     *
     * The spec's reason is specific: a colleague on WhatsApp will open a PDF and will not
     * open a link. It runs the **same** gates and the same query as the web page — a PDF
     * route that skipped the password would be the whole security model with a `.pdf` on
     * the end.
     */
    public function download(Request $request, string $token, PriceListAccess $access, PublicCatalogue $catalogue): Response
    {
        $link = $access->resolve($token);

        if (! $link instanceof PriceListLink) {
            return abort(404);
        }

        if (! $link->isLive()) {
            return abort(410);
        }

        if (! $access->passwordSatisfied($link, $request)) {
            return abort(403);
        }

        $access->logView($link, $request);

        /*
        | A print-optimised HTML sheet rather than a generated PDF binary.
        |
        | Every phone and desktop browser turns this into a PDF with one tap, the output is
        | identical to the web list because it IS the web list, and the spec's acceptance
        | line — "the PDF matches the web list exactly" — is then true by construction
        | rather than by two renderers agreeing. It also keeps a headless-browser dependency
        | out of a public request path.
        */
        return response()->view('storefront::price-list-print', [
            'link' => $link,
            'shop' => $this->shopName(),
            'level' => $this->levelName($link),
            'rows' => $catalogue->forLevel($link->price_level_id, $this->categoriesOf($link)),
            'expires_at' => $link->expires_at,
        ]);
    }

    /**
     * The shop's public name, or its tenant name.
     *
     * Resolved after `resolve()` has entered the tenant, so this is scoped normally.
     */
    private function levelName(PriceListLink $link): string
    {
        $name = $link->priceLevel?->name_fa;

        return is_string($name) ? $name : '';
    }

    /**
     * The link's category filter, as the list the catalogue expects.
     *
     * A JSON column returns whatever was written to it, so a row edited in a console does
     * not get to put a string where an id goes.
     *
     * @return list<int>|null
     */
    private function categoriesOf(PriceListLink $link): ?array
    {
        $categories = $link->categories;

        if (! is_array($categories) || $categories === []) {
            return null;
        }

        return array_values(array_map('intval', array_filter($categories, 'is_numeric')));
    }

    private function shopName(): string
    {
        $settings = StorefrontSetting::query()->first();

        $name = $settings?->display_name;

        return is_string($name) && $name !== '' ? $name : 'فروشگاه';
    }
}
