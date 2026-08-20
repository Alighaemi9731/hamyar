<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Http\Requests\OnboardTenantRequest;
use App\Modules\Platform\Models\Domain;
use App\Modules\Platform\Services\TenantProvisioner;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Shop onboarding — the first ninety seconds of the product.
 *
 * Lives on the central domain: there is no tenant yet.
 */
final class OnboardingController extends Controller
{
    public function __construct(private readonly TenantProvisioner $provisioner) {}

    /**
     * Blade, not Inertia, and that is the same call the landing and the legal pages
     * made: this page is read by somebody with no session, it has to match the public
     * design language exactly, and that language lives in a Blade stylesheet
     * (ADR 0016). Rendering it through React meant it inherited the *application's*
     * look instead — which is precisely why it did not match.
     */
    public function create(): View
    {
        return view('auth.register');
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

        /*
        | Same origin, always. A LINK crosses the hostname boundary, never this redirect.
        |
        | This is the THIRD form of one bug, and the second time it has shipped.
        |
        | `redirect()->away()` was blocked by `connect-src` when Inertia followed it as
        | an XHR — fixed in eb76853 with `Inertia::location()`. Rebuilding this page in
        | Blade then turned the form into an ordinary POST, `Inertia::location()`
        | degraded to the plain 302 it promises for non-Inertia requests, and Chrome
        | blocked THAT against `form-action 'self'`, which it checks against redirect
        | targets while naming the original action in the error:
        |
        |     Sending form data to 'https://<apex>/register' violates the following
        |     Content Security Policy directive: "form-action 'self'".
        |
        | The failure mode is the one worth designing against, and it is identical both
        | times: the POST has already succeeded, so the shop exists and the owner's
        | account exists, and the person is left on an unchanged form. Pressing the
        | button again reports the number as taken — by themselves — for a shop whose
        | address they were never shown.
        |
        | The lesson is narrower than "CSP is fiddly": **the destination is on another
        | origin, and no redirect out of a form POST can reach it.** Only a link can. So
        | the redirect stays here and the address is handed over on the next page.
        |
        | ADR 0017 removes the cross-origin destination altogether, at which point this
        | could be a plain redirect again — but the hand-over page should stay, because
        | "your shop is ready, here is its address" is worth reading rather than
        | flashing past.
        */
        $port = $request->getPort();
        $suffix = in_array($port, [80, 443], true) ? '' : ":{$port}";

        return redirect()->route('register.done')->with('registered', [
            'shop' => $tenant->name,
            'login_url' => $request->getScheme().'://'.Domain::hostnameFor($tenant->slug).$suffix.'/login',
        ]);
    }

    /**
     * Hands over the new shop's address.
     *
     * The destination comes from the session flash and from nowhere else. Accepting it
     * from the query string would let anybody render this page pointing at any hostname
     * they chose — a phishing gift on a page whose entire job is "click here to sign in".
     */
    public function done(Request $request): SymfonyResponse
    {
        $registered = $request->session()->get('registered');

        // Both values are checked as strings rather than cast. Session data is `mixed`
        // as far as the analyser is concerned and it is right to insist: this page
        // renders a URL as an href, so "whatever was in the session, stringified" is
        // not a good enough guarantee for the one thing on it a person will click.
        $shop = is_array($registered) && is_string($registered['shop'] ?? null)
            ? $registered['shop']
            : null;

        $loginUrl = is_array($registered) && is_string($registered['login_url'] ?? null)
            ? $registered['login_url']
            : null;

        if ($shop === null || $loginUrl === null) {
            return redirect()->route('register');
        }

        return response()->view('auth.register-done', [
            'shop' => $shop,
            'loginUrl' => $loginUrl,
        ]);
    }
}
