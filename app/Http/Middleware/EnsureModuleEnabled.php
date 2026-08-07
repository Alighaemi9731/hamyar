<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Platform\Services\SubscriptionResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level plan gating: `->middleware('module:repairs')`.
 *
 * Golden rule 7 asks for gating in two places, and this is the one that matters. The
 * `features` shared prop hides a nav item; hiding UI is not authorization, and anyone
 * can type a URL. If only one of the two existed, it would have to be this one.
 *
 * 403 rather than 404: the shop knows perfectly well the Repairs module exists — it is
 * on our pricing page — so pretending the route is missing would be both dishonest and
 * unhelpful. The response says "your plan does not include this", which is also the
 * upsell.
 */
final class EnsureModuleEnabled
{
    public function __construct(private readonly SubscriptionResolver $resolver) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        if ($this->resolver->grants($module)) {
            return $next($request);
        }

        abort(403, 'این بخش در پلن فعلی فروشگاه شما فعال نیست.');
    }
}
