<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns on the platform read flag for the duration of a super-admin request.
 *
 * The Filament panel exists to look across every shop — MRR, churn, who is past due —
 * and the billing tables are RLS-protected on `tenant_id`. Without this the panel would
 * run with no tenant context and see nothing at all.
 *
 * The flag is narrow: only the billing policies consult it, so this does NOT hand the
 * panel a shop's invoices, customers or stock. Reaching those still requires entering a
 * specific tenant's context deliberately, which is what makes impersonation an auditable
 * act rather than an ambient capability.
 *
 * Cleared in a `finally` so a thrown exception cannot leave a pooled connection able to
 * read across tenants for whatever request gets it next.
 */
final class PlatformPanelContext
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $this->context->runAsPlatform(static fn (): Response => $next($request));
    }
}
