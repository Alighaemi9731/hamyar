<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // `@vite` reads public/build/manifest.json, a gitignored build artefact. It
        // exists on a machine that has run `npm run build` and nowhere else, so
        // without this the suite is green locally and 500s in CI — which is exactly
        // how it failed on the first CI run.
        //
        // Feature tests assert the server's response, not the asset pipeline; the
        // build is covered by its own CI job and by the browser checks.
        if ($this->shouldDisableVite()) {
            $this->withoutVite();
        }
    }

    /**
     * Sign a user in, the way the application actually signs one in.
     *
     * ## Why this override exists
     *
     * [ADR 0017](../docs/adr/0017-single-host-app.md) moved every shop to one address,
     * so the tenant is no longer read from the hostname — it is written into the session
     * at login, from the authenticated user's own record, and `ResolveTenant` reads it on
     * every later request.
     *
     * `actingAs()` injects a user straight into the guard and touches no session, so
     * without this every authenticated feature test would arrive with a user and no
     * tenant, and be redirected to the login page. Roughly a thousand of them were.
     *
     * ## Why the fix is here and not in ResolveTenant
     *
     * The obvious alternative — have `ResolveTenant` fall back to `Auth::user()`'s
     * tenant when the session has none — **cannot work in production and would still
     * make the suite green.** The session guard resolves a user through the
     * tenant-scoped provider, so with no tenant pinned RLS returns nothing and there is
     * no user to fall back to. It would only ever succeed in tests, where `actingAs()`
     * pre-injects the user, and the whole suite would then be passing for a reason
     * production does not have.
     *
     * So the tests do what login does. "Signed in" in this application means the session
     * carries the tenant, and that belongs in the base class rather than in every one of
     * the call sites.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable  $user
     */
    public function actingAs($user, $guard = null): static
    {
        parent::actingAs($user, $guard);

        /*
        | Platform administrators have no tenant and must not acquire one: the panel
        | reads across every shop, and pinning one would silently scope it to that shop.
        |
        | The column is checked for EXISTENCE rather than read and compared to null.
        | `preventAccessingMissingAttributes()` is on outside production (AppServiceProvider),
        | so `getAttribute('tenant_id')` on a PlatformUser throws MissingAttributeException
        | rather than returning null — which is the strictness working as intended, and
        | took out twelve admin-panel tests when this override first landed.
        */
        if ($user instanceof Model && array_key_exists('tenant_id', $user->getAttributes())) {
            $this->withSession(['tenant_id' => $user->getAttribute('tenant_id')]);
        }

        return $this;
    }

    /**
     * Whether `@vite` should be stubbed out for this test.
     *
     * True everywhere except browser tests, which are the one kind that needs the real
     * thing: `withoutVite()` makes the directive emit nothing, so the page arrives with
     * its Inertia payload and **no script tag to consume it** — a blank white body, no
     * JavaScript error, and a failure message about text not being visible that says
     * nothing about why. {@see BrowserTestCase}
     */
    protected function shouldDisableVite(): bool
    {
        return true;
    }
}
