<?php

declare(strict_types=1);

use App\Support\Database\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;

/**
 * `users` becomes readable under the platform escape.
 *
 * ## Why this is necessary, and why it is not a weakening
 *
 * [ADR 0017](../../../../../docs/adr/0017-single-host-app.md) put every shop on one
 * address. **Authenticating therefore has to happen before any tenant is known** — the
 * tenant is what authenticating produces. There is no hostname left to pin one from, so
 * the lookup of "which account owns this mobile number" must see across tenants.
 *
 * `withoutTenancy()` is not enough on its own, and finding that out is what produced
 * this migration. It removes the *Eloquent* global scope — layer 2 of ADR 0002 — while
 * layer 1, the Postgres policy, still denies every row because no `app.tenant_id` is
 * set. The symptom was a 500 during login and, had the dummy-hash branch not thrown, it
 * would have been worse: **every correct password reported as wrong**, with nothing in
 * any log to say why.
 *
 * ## What it actually grants
 *
 * The policy gains `OR current_setting('app.platform', true) = '1'`, and that flag is
 * set by exactly one thing: `TenantContext::runAsPlatform()`. So this does not open
 * `users` to the application — a request that has not deliberately entered that block
 * still sees only its own shop. It makes cross-tenant reads of `users` *possible* and
 * *greppable*, which is the same bargain `subscriptions` and `subscription_invoices`
 * already accepted under ADR 0002's amendment.
 *
 * The one consumer today is `AccountLookup`, which verifies a password and returns a
 * single row. The Filament panel's user administration is the other legitimate caller.
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        // Re-declares the policy; enableRls drops and recreates it.
        $this->enableRls('users', allowPlatform: true);
    }

    public function down(): void
    {
        $this->enableRls('users');
    }
};
