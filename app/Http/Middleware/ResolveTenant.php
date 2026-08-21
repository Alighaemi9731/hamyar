<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the tenant from the SESSION and pins it for the request.
 *
 * Layer 1 of [ADR 0002](../../../docs/adr/0002-single-db-tenancy-rls.md), amended by
 * [ADR 0017](../../../docs/adr/0017-single-host-app.md).
 *
 * ## It used to be the hostname, and that is the change
 *
 * Every shop had its own address and this middleware read `domains.hostname`. One
 * address serves every shop now, so the tenant is established once — at login, from the
 * authenticated user's own `tenant_id` — and carried in the session.
 *
 * ## What that changes about the guarantee, precisely
 *
 * Host-only session cookies used to do part of the work: a session established at
 * shop-a could not even be *sent* to shop-b, because the browser would not attach it to
 * a different hostname. On one host that protection is gone and the session's own
 * `tenant_id` carries the whole weight.
 *
 * That is sound, and it is worth being exact about why: the value is **server-side
 * session state written from the authenticated user's record**, never from a request
 * parameter, a header or a cookie the client can author. There is no request shape that
 * makes this middleware adopt a tenant the visitor did not authenticate into. The one
 * rule that keeps it true: nothing outside the login flow may ever write
 * `session('tenant_id')`.
 *
 * `EnsureTenantUser` remains the second half of the pair — it checks the authenticated
 * user still belongs to the pinned tenant, which is what catches a session whose user
 * was moved or deleted.
 *
 * ## The rest is unchanged, and each is deliberate
 *
 * · **No tenant in session → the login page.** Never a fallback to a "default" tenant.
 * · **Suspended tenant → 403 with a real explanation**, not 404. The shop exists and its
 *   owner needs to know it is suspended rather than deleted.
 * · **The context is cleared after the response.** On a long-lived worker the container
 *   survives the request; a leftover context would apply to the next one.
 */
final class ResolveTenant
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->resolve($request);

        if (! $tenant instanceof Tenant) {
            /*
            | No pinned tenant. This is the ordinary state of a visitor who has not
            | signed in yet, not an error, so it is a redirect rather than a 404 — the
            | old hostname-based version 404'd because an unknown *address* really was
            | nothing, whereas an unauthenticated *session* is just an unauthenticated
            | session.
            |
            | The session is flushed first. A half-populated session that survives a
            | failed resolution is how a stale `tenant_id` outlives the account it came
            | from.
            */
            if ($request->expectsJson()) {
                abort(401, 'برای ادامه وارد شوید.');
            }

            $request->session()->forget('tenant_id');

            return redirect()->guest(route('login'));
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

    private function resolve(Request $request): ?Tenant
    {
        $id = $request->session()->get('tenant_id');

        // Written only by the login flow, but read here on every request, so it is
        // validated rather than trusted: a session carrying a string, an array or an id
        // that no longer exists resolves to nothing and lands on the login page.
        if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
            return null;
        }

        return Tenant::query()->find((int) $id);
    }
}
