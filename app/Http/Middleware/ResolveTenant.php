<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Platform\Models\Domain;
use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the tenant from the request's hostname and pins it for the request.
 *
 * Layer 1 of [ADR 0002](../../../docs/adr/0002-single-db-tenancy-rls.md). Applied to
 * the tenant route group only; central routes (onboarding, billing, the platform
 * panel) run with no tenant at all, and RLS denies them every tenant table by default.
 *
 * Behaviour worth stating, because each is a deliberate security choice:
 *
 * · **Unknown hostname → 404.** Never a fallback to a default tenant. A typo must not
 *   silently serve somebody else's shop.
 * · **Suspended tenant → 403 with a real explanation**, not 404. The shop exists and
 *   its owner needs to know it is suspended rather than deleted.
 * · **The context is cleared after the response.** On a long-lived worker (Octane) the
 *   container survives the request; a leftover context would apply to the next one.
 */
final class ResolveTenant
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->resolve($request->getHost());

        if (! $tenant instanceof Tenant) {
            abort(404, 'فروشگاهی با این نشانی پیدا نشد.');
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

    private function resolve(string $host): ?Tenant
    {
        // Ports never reach getHost(), but an explicit lowercase keeps a mixed-case
        // Host header from missing an exact-match lookup.
        $hostname = mb_strtolower($host);

        $domain = Domain::query()
            ->with('tenant')
            ->where('hostname', $hostname)
            ->first();

        return $domain?->tenant;
    }
}
