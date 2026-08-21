<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Platform\Services\PublicTenantResolver;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pins the tenant for a page reached with an unguessable token and no session.
 *
 * ## Why this is separate from ResolveTenant
 *
 * `ResolveTenant` reads the session, which is right for every screen a shopkeeper uses.
 * These pages are the opposite case: a customer scanning the QR on their repair receipt,
 * or opening a price list a reseller sent them. They have never signed in, they have no
 * session, and under [ADR 0017](../../../docs/adr/0017-single-host-app.md) there is no
 * hostname left to say which shop they belong to either.
 *
 * The token does. Each of these is unique across the whole platform on purpose — the
 * category `TenancyCheckCommand::GLOBALLY_UNIQUE_BY_DESIGN` exists for — so it names one
 * row, and that row names the tenant.
 *
 * ## The two properties that make this safe
 *
 * · **The token is the credential.** 48–64 random characters, and holding one is the
 *   entire authorisation. That is the same bargain as before this change; what moved is
 *   only where the tenant comes from.
 * · **A miss is a 404, never a fallback.** An unknown or malformed token resolves to no
 *   tenant and the request stops. It must never continue with the context left over from
 *   whatever the worker handled last, which is why the context is cleared in a `finally`
 *   exactly as `ResolveTenant` does.
 */
final class ResolvePublicTenant
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PublicTenantResolver $resolver,
    ) {}

    /**
     * @param  string  $surface  a key of PublicTenantResolver::SURFACES
     * @param  string  $parameter  the route parameter carrying the token
     */
    public function handle(Request $request, Closure $next, string $surface, string $parameter = 'token'): Response
    {
        $token = $request->route($parameter);

        $tenant = is_string($token) ? $this->resolver->resolve($surface, $token) : null;

        if ($tenant === null) {
            // The same 404 an unknown token has always produced. Deliberately identical
            // to "no such ticket": distinguishing "wrong shop" from "no such token" would
            // tell a guesser they were close.
            abort(404);
        }

        if (! $tenant->isUsable()) {
            abort(403, 'دسترسی به این فروشگاه موقتاً غیرفعال است.');
        }

        $this->context->set($tenant);

        try {
            return $next($request);
        } finally {
            $this->context->forget();
        }
    }
}
