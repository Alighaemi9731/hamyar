<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Platform\Models\Module;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level module kill-switch: `->middleware('module:repairs')`.
 *
 * ## What this used to mean, and what it means now
 *
 * Until DECISION GATE 6 this asked "does this shop's plan include Repairs?" and 403'd if
 * not. Plans no longer bundle modules — every module is open to every shop, and what a
 * plan sells is how much work a shop may record in a month (ADR 0018). So the question
 * changed while the mechanism stayed: it now asks **"have WE switched this module on?"**,
 * reading `modules.is_enabled`, which nothing but the super-admin panel can write.
 *
 * Keeping it was deliberate. ADR 0011 ships Moadian as an adapter with no provider behind
 * it, and something has to be able to close those routes for everybody without a deploy.
 * Thirteen route groups, the nav, and the dashboard's widget list already consume this
 * shape correctly for that question; deleting it would have been a twenty-file diff to
 * arrive back where we started.
 *
 * ## What replaced it as the thing that says "no"
 *
 * The quota layer. A shop that has spent its monthly credit is refused at the point of
 * *creating* something, with a Persian sentence and an upgrade button — not by having
 * every screen in the module 403 at it. That distinction matters: a shop whose payment is
 * three days late used to find the whole product broken, which is a support ticket that
 * begins "your software has stopped working" and is not wrong.
 *
 * 403 rather than 404 because the shop knows the module exists — it is on our pricing
 * page — and the copy says the true thing: this is off for everybody right now.
 */
final class EnsureModuleEnabled
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        if (Module::isEnabledPlatformWide($module)) {
            return $next($request);
        }

        abort(403, 'این بخش موقتاً در دسترس نیست.');
    }
}
