<?php

declare(strict_types=1);

use App\Support\Database\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;

/**
 * `storefront_settings` becomes readable under the platform escape.
 *
 * ## Why this is necessary
 *
 * [ADR 0017](../../../../../docs/adr/0017-single-host-app.md) moved a shop's public
 * window from `<shop>.<apex>/shop` to `app.<apex>/shop/{slug}`. The hostname used to say
 * whose window it was; now the slug does, and `PublicTenantResolver` has to read it
 * **before any tenant is known** — a visitor to a public page has no session at all.
 *
 * `withoutTenancy()` is not enough on its own. It removes the *Eloquent* global scope —
 * layer 2 of ADR 0002 — while layer 1, the Postgres policy, still denies every row
 * because no `app.tenant_id` is set. The symptom is not an error: it is every public
 * shop page returning 404, indistinguishable from a slug that was never registered.
 * That is the identical bug the `users` migration beside this one was written for.
 *
 * ## What it actually grants
 *
 * The policy gains `OR current_setting('app.platform', true) = '1'`, set by exactly one
 * thing: `TenantContext::runAsPlatform()`. An ordinary request still sees only its own
 * shop's row. And the table is the right one to open: `slug`, `display_name`, the phone
 * number and the address on it are what the page publishes to the whole internet
 * anyway. `price_list_links` next to it already made the same bargain at creation.
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        // Re-declares the policy; enableRls drops and recreates it.
        $this->enableRls('storefront_settings', allowPlatform: true);
    }

    public function down(): void
    {
        $this->enableRls('storefront_settings');
    }
};
