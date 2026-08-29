<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Quota\QuotaExceeded;
use App\Support\Quota\QuotaGuard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A courtesy check before the work starts: `->middleware('quota:sales.invoices')`.
 *
 * ## This is not the guarantee, and must never be mistaken for it
 *
 * `QuotaGuard::consume()` is the only thing that actually holds the line, because it is
 * the only thing that runs inside the transaction that writes the counted row. This runs
 * before any of that — before the validation, the locks and the domain writes — purely so
 * a shop that is already out of credit gets its answer without the server doing work it is
 * about to throw away.
 *
 * Three request paths in this application reach tenant tables **outside** the `tenant`
 * middleware group (invitation accept, password reset, the public price list), and no job
 * has middleware at all. A design that relied on this would have three holes and not know
 * it.
 *
 * ## GETs are never checked
 *
 * Reads are not metered. A shop that has spent its credit can still look up a customer,
 * print a receipt and run a report — it just cannot record new work until the month turns.
 * Guarding a GET here would take the product away from someone who has paid for the data
 * that is in it.
 *
 * ## Not on the POS route, deliberately
 *
 * `sales.pos.store` is one endpoint with three jobs — park a basket, issue a quote,
 * finalise an invoice — chosen by an `action` field in the body. A metric on the route
 * would refuse the first two because the third is exhausted, so the POS relies on the
 * service-level check, which knows which of the three is happening.
 */
final class EnsureQuotaAvailable
{
    public function __construct(private readonly QuotaGuard $guard) {}

    public function handle(Request $request, Closure $next, string $metric, string $units = '1'): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        $verdict = $this->guard->check($metric, max(1, (int) $units));

        if (! $verdict->allowed) {
            // The same exception `consume()` throws, so the shopkeeper cannot tell the two
            // apart: one sentence, one upgrade button, on the screen they were already on.
            throw new QuotaExceeded($verdict);
        }

        return $next($request);
    }
}
