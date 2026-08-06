<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Http\Requests\OnboardTenantRequest;
use App\Modules\Platform\Models\Domain;
use App\Modules\Platform\Services\TenantProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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

    public function store(OnboardTenantRequest $request): RedirectResponse
    {
        $tenant = $this->provisioner->provision($request->provisioningData());

        // Send them to their own subdomain to log in. We deliberately do NOT
        // auto-authenticate across the hostname boundary: the session cookie is scoped
        // to the tenant domain, and a first real login proves the credentials they just
        // chose actually work before they walk away from the screen.
        $hostname = Domain::hostnameFor($tenant->slug);
        $port = $request->getPort();
        $suffix = in_array($port, [80, 443], true) ? '' : ":{$port}";

        return redirect()
            ->away($request->getScheme().'://'.$hostname.$suffix.'/login')
            ->with('success', 'فروشگاه شما ساخته شد. حالا وارد شوید.');
    }
}
