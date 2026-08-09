<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\PlatformUser;
use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Facades\URL;

/**
 * Letting support log in as a shop owner, on the record.
 *
 * Impersonation is the single most dangerous capability in the product: it hands a
 * staff member a real shop's customers, prices and cash position. So the design makes it
 * awkward in exactly the ways that matter, and cheap in the ways that do not:
 *
 * - **It is always audited, before it happens.** The activity record is written first,
 *   so a crash mid-flow leaves evidence of the attempt rather than a silent session.
 * - **A reason is mandatory** and stored with the record. "Which support ticket?" is a
 *   question that should already be answered.
 * - **The session is short-lived.** A signed URL valid for two minutes creates the
 *   session; the link cannot be forwarded, bookmarked, or replayed tomorrow.
 * - **The shop can see it.** The record lands in the tenant's own activity log, so
 *   owners can audit us the way we audit them. That is the property that makes the
 *   feature defensible to a customer.
 *
 * What it deliberately does NOT do is grant platform staff a standing ability to read
 * tenant data. Outside an impersonated session they have the `app.platform` flag, which
 * only opens billing (ADR 0002 amendment) — never a shop's own tables.
 */
final class ImpersonationService
{
    /**
     * How long the hand-off link lives. Long enough to follow a redirect, short enough
     * that a link pasted into a chat is already dead.
     */
    private const LINK_TTL_SECONDS = 120;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuthFactory $auth,
    ) {}

    /**
     * Begin impersonating `$tenant`'s owner.
     *
     * @return string|null a one-time signed URL on the tenant's hostname, or null when
     *                     the shop has no active owner to impersonate
     */
    public function start(Tenant $tenant, string $reason): ?string
    {
        $staff = $this->auth->guard('platform')->user();

        if (! $staff instanceof PlatformUser) {
            return null;
        }

        $owner = $this->context->runFor(
            $tenant,
            fn (): ?User => User::query()
                ->where('is_active', true)
                ->whereHas('roles', fn ($query) => $query->where('name', 'Owner'))
                ->orderBy('id')
                ->first()
        );

        if (! $owner instanceof User) {
            return null;
        }

        $hostname = $tenant->domains()->orderBy('id')->value('hostname');

        if (! is_string($hostname) || $hostname === '') {
            return null;
        }

        $this->record($tenant, $staff, $owner, $reason);

        return $this->signedOnHost($hostname, $owner);
    }

    /**
     * Mint the signed link on the TENANT's hostname.
     *
     * The signature covers the whole URL, host included, and it is verified against the
     * request that arrives at the shop's subdomain. Signing on the central domain and
     * rewriting the host afterwards produces a link that always fails validation, so the
     * root is switched before signing and restored immediately after.
     *
     * The hostname comes from the tenant's `domains` row — the apex is never assembled
     * from a literal (golden rule 1b).
     */
    private function signedOnHost(string $hostname, User $owner): string
    {
        $scheme = str_starts_with(config()->string('app.url'), 'https://') ? 'https' : 'http';

        URL::forceRootUrl("{$scheme}://{$hostname}");

        try {
            return URL::temporarySignedRoute(
                'impersonate.start',
                CarbonImmutable::now()->addSeconds(self::LINK_TTL_SECONDS),
                ['user' => $owner->getKey()],
                absolute: true
            );
        } finally {
            // Cleared, NOT restored to config('app.url'). Passing a value here pins the
            // generator's root for the rest of the process, so every later route() —
            // including redirects on a tenant subdomain — would come out on the central
            // domain. Null puts it back to deriving the root from the request.
            URL::forceRootUrl(null);
        }
    }

    /**
     * Write the audit trail into the TENANT's activity log.
     *
     * Deliberately inside `runFor`: this record belongs to the shop, is visible on their
     * own activity screen, and is covered by their RLS policy like any other row of
     * theirs. Logging it centrally instead would make it invisible to the only party
     * with a real interest in reading it.
     */
    private function record(Tenant $tenant, PlatformUser $staff, User $owner, string $reason): void
    {
        $this->context->runFor($tenant, function () use ($staff, $owner, $reason): void {
            activity('impersonation')
                ->performedOn($owner)
                // Causer is the impersonated owner, because the tenant activity log only
                // knows tenant users — the platform staff member is recorded in the
                // properties below, which is where an owner reading their own log will
                // look for "who was this really".
                ->causedBy($owner)
                ->withProperties([
                    'platform_user_id' => $staff->getKey(),
                    'platform_user_email' => $staff->email,
                    'reason' => $reason,
                    'ip' => request()->ip(),
                ])
                ->log('ورود پشتیبانی موبی‌شاپ به حساب مالک');
        });
    }
}
