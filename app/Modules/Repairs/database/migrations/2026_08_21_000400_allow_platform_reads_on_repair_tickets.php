<?php

declare(strict_types=1);

use App\Support\Database\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;

/**
 * `repair_tickets` becomes readable under the platform escape.
 *
 * ## Why this is necessary
 *
 * [ADR 0017](../../../../../docs/adr/0017-single-host-app.md) moved the two customer-
 * facing repair links — the QR printed on the intake receipt (`/t/{tracking_token}`) and
 * the quote-approval link (`/a/{approval_token}`) — onto one address, and made both
 * tokens globally unique so they can identify a ticket on their own. `PublicTenantResolver`
 * reads them **before any tenant is known**: the person scanning that QR is a customer
 * with no account and no session.
 *
 * `withoutTenancy()` is not enough on its own. It removes the *Eloquent* global scope —
 * layer 2 of ADR 0002 — while layer 1, the Postgres policy, still denies every row
 * because no `app.tenant_id` is set. The symptom is a 404 on every tracking page, which
 * reads to the shop as "the QR codes are broken" and to the customer as a lost phone.
 *
 * ## What it actually grants, and why the blast radius is smaller than it looks
 *
 * The policy gains `OR current_setting('app.platform', true) = '1'`, set by exactly one
 * thing: `TenantContext::runAsPlatform()`. The one caller is `PublicTenantResolver`, and
 * the single statement it runs under the flag selects **`tenant_id` only**, from a row
 * named by a 64-character random token. The moment it has that id it leaves the flag and
 * enters the tenant properly, so the page's own reads — the ticket, its history, the
 * customer — are scoped by RLS exactly as before. `ticket_status_histories` and
 * `ticket_checklist_answers` deliberately do NOT opt in: they are read after the context
 * is entered.
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        // Re-declares the policy; enableRls drops and recreates it.
        $this->enableRls('repair_tickets', allowPlatform: true);
    }

    public function down(): void
    {
        $this->enableRls('repair_tickets');
    }
};
