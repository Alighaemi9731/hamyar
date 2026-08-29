<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Services;

use App\Modules\Platform\Models\Tenant;
use App\Modules\Storefront\Models\PriceListLink;
use App\Modules\Storefront\Models\PriceListView;
use App\Support\Quota\QuotaGuard;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Minting, resolving and logging reseller price-list links.
 *
 * Every security rule the spec lists lives here, in one object, because they are only
 * guarantees if they cannot be reached around:
 *
 * - **Expired → 410, and never the prices.** The check runs before anything is loaded.
 * - **Wrong password → 403**, rate-limited by the route.
 * - **The token grants only the price level it was minted with.** There is nowhere in the
 *   request for a price level to come from; it is a column on the row.
 * - **Revoking takes effect immediately.** No cache sits in front of this.
 * - **Every view is logged**, so a shop can see a link travelling further than it sent it.
 *
 * ## The token is a bearer credential and is stored hashed
 *
 * Whoever holds the URL gets reseller prices — the figures a shop most wants kept off its
 * public page. Storing the raw token would put every live price list in plaintext into any
 * dump, replica or backup. So the token is shown **once** at creation and only its hash is
 * kept, with a short non-secret `lookup` prefix so the row can still be found in one query.
 *
 * ## Resolution runs as a platform read, and that is narrow by design
 *
 * A visitor arrives holding a token and nothing else: no session, no subdomain necessarily,
 * no tenant. There is nothing to scope the lookup by until the link is found — so the
 * lookup itself is the one statement that crosses tenants, exactly as ADR 0002's amendment
 * permits, and the very next thing it does is *enter* that tenant's context. Everything the
 * page reads afterwards is scoped normally by RLS.
 */
final class PriceListAccess
{
    /** The default the spec names. A link that never expires leaks and is never noticed. */
    public const DEFAULT_DAYS = 7;

    /**
     * The non-secret prefix of a token, and the width of the `lookup` column.
     *
     * Public because `PublicTenantResolver` has to split a token exactly the same way to
     * find the shop before anything is scoped. Two copies of `12` in two modules is the
     * shape of bug where one of them is changed and the other keeps matching nothing.
     */
    public const LOOKUP_LENGTH = 12;

    public function __construct(
        private readonly QuotaGuard $quota,
        private readonly ConnectionInterface $connection,
    ) {}

    /**
     * Mint a link. The plaintext token is returned **once** and never stored.
     *
     * @param  list<int>|null  $categories
     * @return array{link: PriceListLink, token: string}
     */
    public function mint(
        int $priceLevelId,
        ?string $password = null,
        ?CarbonImmutable $expiresAt = null,
        ?string $label = null,
        ?array $categories = null,
        ?int $actorId = null,
    ): array {
        // 32 bytes of randomness. The lookup prefix is part of the same string, so a
        // visitor pastes one token and the server splits it — two values to copy would be
        // two values to get wrong.
        $token = Str::random(self::LOOKUP_LENGTH).Str::random(32);

        /** @var PriceListLink $link */
        $link = $this->connection->transaction(fn (): PriceListLink => $this->createLink(
            $token, $priceLevelId, $password, $expiresAt, $label, $categories, $actorId
        ));

        return ['link' => $link, 'token' => $token];
    }

    /**
     * The row, and the credit it spends, in one transaction.
     *
     * A standing capacity rather than a monthly flow: a revoked or expired link gives the
     * slot back, so what a plan caps is how many are live at once. Revoking a leaked price
     * list is exactly the act that must stay free.
     *
     * @param  list<int>|null  $categories
     */
    private function createLink(
        string $token,
        int $priceLevelId,
        ?string $password,
        ?CarbonImmutable $expiresAt,
        ?string $label,
        ?array $categories,
        ?int $actorId,
    ): PriceListLink {
        $this->quota->consume('storefront.price_list_links');

        /** @var PriceListLink $link */
        $link = PriceListLink::query()->create([
            'lookup' => substr($token, 0, self::LOOKUP_LENGTH),
            'token_hash' => Hash::make($token),
            'label' => $label,
            'price_level_id' => $priceLevelId,
            'password_hash' => $password === null || $password === '' ? null : Hash::make($password),
            'expires_at' => $expiresAt ?? CarbonImmutable::now()->addDays(self::DEFAULT_DAYS),
            'categories' => $categories,
            'created_by' => $actorId,
        ]);

        return $link;
    }

    /**
     * Find the link a token names, and enter its tenant.
     *
     * Returns null for "no such link" **and** for a token whose hash does not verify — the
     * two are the same answer to a visitor, and distinguishing them would turn this into an
     * oracle for guessing lookups.
     *
     * Does NOT check expiry, revocation or password: the caller needs those separately so it
     * can answer 410 versus 403. What it does guarantee is that after it returns, the tenant
     * context is the link's tenant.
     *
     * @param  string  $token  the raw token from the URL — never trusted, never logged
     */
    public function resolve(string $token): ?PriceListLink
    {
        $lookup = substr($token, 0, self::LOOKUP_LENGTH);

        if (strlen($lookup) < self::LOOKUP_LENGTH) {
            return null;
        }

        /*
        | The one cross-tenant statement, and it is a single indexed row.
        |
        | A visitor holding a token has no tenant to be scoped by — that is what the token
        | is for. `runAsPlatform` is ADR 0002's narrow, deliberate escape, and the query is
        | as small as it can be: one lookup, no joins, no user input beyond the prefix.
        */
        $row = app(TenantContext::class)->runAsPlatform(
            fn () => DB::table('price_list_links')->where('lookup', $lookup)->first()
        );

        if ($row === null) {
            return null;
        }

        $values = (array) $row;
        $hash = is_string($values['token_hash'] ?? null) ? $values['token_hash'] : '';

        // Constant-time by `Hash::check`. A `===` on the raw token is what the hashing was
        // for, and comparing it here would give the timing back.
        if (! Hash::check($token, $hash)) {
            return null;
        }

        $tenantId = is_numeric($values['tenant_id'] ?? null) ? (int) $values['tenant_id'] : 0;
        $tenant = app(TenantContext::class)->runAsPlatform(fn () => Tenant::query()->find($tenantId));

        if (! $tenant instanceof Tenant) {
            return null;
        }

        // From here on everything is scoped normally: the page's catalogue read, the view
        // log, all of it under this tenant's RLS.
        app(TenantContext::class)->set($tenant);

        $id = is_numeric($values['id'] ?? null) ? (int) $values['id'] : 0;

        return PriceListLink::query()->find($id);
    }

    /**
     * Whether the visitor has cleared this link's password.
     *
     * A link with no password is open to anybody holding the URL, which is the point of it.
     */
    public function passwordSatisfied(PriceListLink $link, Request $request): bool
    {
        if (! $link->needsPassword()) {
            return true;
        }

        return $request->session()->get($this->sessionKey($link)) === true;
    }

    /**
     * Check a submitted password and remember the answer for this session.
     */
    public function attemptPassword(PriceListLink $link, Request $request, string $password): bool
    {
        if (! $link->needsPassword()) {
            return true;
        }

        if (! Hash::check($password, (string) $link->password_hash)) {
            return false;
        }

        $request->session()->put($this->sessionKey($link), true);

        return true;
    }

    /**
     * Record a view, and bump the counters a shop reads at a glance.
     *
     * The counters are denormalised on purpose and it is not a golden-rule-3 violation:
     * they are a convenience over `price_list_views`, which remains the record. A count that
     * drifts by one is a cosmetic problem; a `COUNT(*)` on every public page view is a real
     * one.
     */
    public function logView(PriceListLink $link, Request $request): void
    {
        PriceListView::query()->create([
            'price_list_link_id' => $link->getKey(),
            'ip' => $request->ip(),
            // Truncated to the column, because a hostile client can send a header of any
            // length and a 22001 on a public page is a 500 the shop cannot explain.
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            'viewed_at' => CarbonImmutable::now(),
        ]);

        $link->forceFill([
            'view_count' => $link->view_count + 1,
            'last_viewed_at' => CarbonImmutable::now(),
        ])->save();
    }

    /**
     * Per link, so clearing one does not open another.
     */
    private function sessionKey(PriceListLink $link): string
    {
        return 'price_list.unlocked.'.idOfModel($link);
    }
}
