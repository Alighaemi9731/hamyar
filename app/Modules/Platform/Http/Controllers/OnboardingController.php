<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Http\Requests\OnboardTenantRequest;
use App\Modules\Platform\Models\Domain;
use App\Modules\Platform\Services\TenantProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Shop onboarding — the first ninety seconds of the product.
 *
 * Lives on the central domain: there is no tenant yet.
 */
final class OnboardingController extends Controller
{
    public function __construct(private readonly TenantProvisioner $provisioner) {}

    public function create(): Response
    {
        return Inertia::render('auth/register', [
            'domain' => config()->string('app.domain'),
        ]);
    }

    /**
     * Live subdomain availability for the wizard's second step.
     */
    public function checkSubdomain(Request $request): JsonResponse
    {
        $subdomain = (string) $request->string('subdomain');

        return response()->json($this->provisioner->checkSubdomain($subdomain));
    }

    public function store(OnboardTenantRequest $request): SymfonyResponse
    {
        $tenant = $this->provisioner->provision($request->provisioningData());

        // Send them to their own subdomain to log in. We deliberately do NOT
        // auto-authenticate across the hostname boundary: the session cookie is scoped
        // to the tenant domain, and a first real login proves the credentials they just
        // chose actually work before they walk away from the screen.
        $hostname = Domain::hostnameFor($tenant->slug);
        $port = $request->getPort();
        $suffix = in_array($port, [80, 443], true) ? '' : ":{$port}";
        $destination = $request->getScheme().'://'.$hostname.$suffix.'/login';

        /*
        | `Inertia::location()`, NOT `redirect()->away()`. This was the latter, and it
        | meant nobody could finish signing up.
        |
        | This form is an Inertia page, so the browser submits it with axios and follows
        | any redirect as an XHR. A shop lives on its own hostname, so the destination is
        | a DIFFERENT ORIGIN — and `connect-src 'self'` in our own CSP blocks it:
        |
        |     Connecting to 'https://<shop>.<apex>/login' violates the following
        |     Content Security Policy directive: "connect-src 'self'".
        |
        | The failure mode is the bad one. The POST had already succeeded, so the shop
        | was provisioned and the owner's account created — and the shopkeeper was left
        | on an unchanged registration form with a network error in a console they will
        | never open. Pressing the button again then fails with "this address is taken",
        | by their own shop, which they cannot reach because they were never told its
        | address.
        |
        | `Inertia::location()` is the primitive for exactly this: it answers 409 with an
        | `X-Inertia-Location` header, which the client turns into a full `window.location`
        | visit rather than an XHR — no cross-origin fetch, so nothing to block. For a
        | non-Inertia request it degrades to an ordinary 302, which is what a `<form>`
        | with JavaScript disabled needs.
        |
        | The flash message that used to ride along is gone, and was always dead: sessions
        | here are host-only cookies (SESSION_DOMAIN is deliberately empty, so a session
        | established at shop-a cannot be presented at shop-b), which means nothing
        | flashed on the apex can be read on the tenant's hostname. The login page it
        | lands on says what to do.
        */
        return Inertia::location($destination);
    }
}
