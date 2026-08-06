<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a session that belongs to a different shop.
 *
 * Defence in depth behind host-only session cookies (`SESSION_DOMAIN` empty). The
 * attack it closes: with a cookie shared across subdomains, a session established at
 * shop-a can be replayed at shop-b. Laravel resolves the stored user id through the
 * tenant-scoped provider, so if shop-b happens to have a user with the same numeric
 * id — entirely likely, since ids are sequential and every shop starts at 1 — the
 * visitor is silently authenticated **as that person**.
 *
 * Cookie scoping alone should prevent it. This makes sure a future config change,
 * a shared-domain deployment or a stolen cookie cannot turn into cross-tenant
 * account takeover.
 */
final class EnsureUserBelongsToTenant
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $tenantId = $this->context->id();

        if ($user !== null && $tenantId !== null) {
            $userTenantId = $user->getAttribute('tenant_id');

            if ($userTenantId !== $tenantId) {
                // Worth an error-level log: under normal operation this cannot happen,
                // so it is either a misconfiguration or someone probing.
                Log::error('Session presented to the wrong tenant; forcing logout.', [
                    'user_id' => $user->getAuthIdentifier(),
                    'user_tenant_id' => $userTenantId,
                    'request_tenant_id' => $tenantId,
                    'ip' => $request->ip(),
                ]);

                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login');
            }
        }

        return $next($request);
    }
}
