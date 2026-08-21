<?php

declare(strict_types=1);

use App\Support\Database\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;

/**
 * `invitations` becomes readable under the platform escape.
 *
 * ## Why this is necessary
 *
 * An invite is opened by somebody who has **no account yet** — that is the entire point
 * of it — so there is no session and, since
 * [ADR 0017](../../../../../docs/adr/0017-single-host-app.md), no hostname either to say
 * which shop the link belongs to. `invitations.token_hash` is globally unique for exactly
 * this reason and is listed in `TenancyCheckCommand::GLOBALLY_UNIQUE_BY_DESIGN` as a
 * bearer credential; `PublicTenantResolver` resolves the shop from it.
 *
 * `withoutTenancy()` is not enough on its own — it removes the *Eloquent* global scope,
 * layer 2 of ADR 0002, while layer 1, the Postgres policy, still denies every row with no
 * `app.tenant_id` set. Without this the accept page 404s for every invite ever sent, and
 * the failure is indistinguishable from an expired link.
 *
 * ## What it actually grants
 *
 * `OR current_setting('app.platform', true) = '1'`, set by exactly one thing:
 * `TenantContext::runAsPlatform()`. The one statement run under it selects `tenant_id`
 * from the row matching a SHA-256 hash of the token the visitor presented — the raw
 * token is never stored and never queried. Everything the accept flow reads afterwards
 * is scoped normally.
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        // Re-declares the policy; enableRls drops and recreates it.
        $this->enableRls('invitations', allowPlatform: true);
    }

    public function down(): void
    {
        $this->enableRls('invitations');
    }
};
