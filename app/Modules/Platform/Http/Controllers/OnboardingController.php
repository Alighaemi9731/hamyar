<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Http\Requests\OnboardTenantRequest;
use App\Modules\Platform\Models\Domain;
use App\Modules\Platform\Services\TenantProvisioner;
use Illuminate\Contracts\View\View;
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

    public function store(OnboardTenantRequest $request): SymfonyResponse
    {
        $tenant = $this->provisioner->provision($request->provisioningData());

        /*
        | Straight to the login page, same origin, no hand-over.
        |
        | This flow has now had three shapes and the first two were CSP failures:
        | `redirect()->away()` blocked by `connect-src` when Inertia followed it as an
        | XHR, then a plain 302 blocked by `form-action 'self'` once the page was Blade.
        | Both existed for one reason — the destination was on ANOTHER HOSTNAME.
        |
        | ADR 0017 removed per-shop hostnames, so there is no boundary left to cross and
        | this is an ordinary redirect. The interstitial that existed to hand over a shop
        | address is gone with the addresses.
        |
        | Still not auto-authenticated, and that part is unchanged on purpose: a first
        | real login proves the credentials they just chose actually work, while they are
        | still at the keyboard to fix a typo.
        */
        return redirect()->route('login')
            ->with('status', 'فروشگاه شما ساخته شد. حالا با شماره موبایل و رمز خود وارد شوید.');
    }
}
