<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The customer's yes, and the exact figure they said it to.
 *
 * ## Why the approval link is not the tracking link
 *
 * The tracking token is printed on the intake receipt, which lives in a pocket and gets
 * shown to people. Approving a quote commits somebody to spending money, so it gets its
 * own secret — minted when the shop asks, sent by SMS to the number on the ticket, and
 * cleared the moment it is used. A receipt somebody photographs cannot authorise work.
 *
 * ## Why the quoted amount is stored beside the token
 *
 * The hazard this closes: the shop quotes 4,500,000 and texts a link; before the
 * customer taps it, somebody edits the estimate to 9,000,000; the customer approves —
 * and, if approval simply copied the current estimate, they have just agreed to a number
 * they never saw.
 *
 * So the figure is frozen at the moment the link is minted. The public page shows
 * `approval_quoted_amount`, the approval records that figure, and changing the estimate
 * while a request is outstanding invalidates the token instead of silently re-pricing
 * it. What the customer approves is what the customer was shown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repair_tickets', function (Blueprint $table): void {
            $table->string('approval_token', 64)->nullable()->after('approval_note');
            $table->unsignedBigInteger('approval_quoted_amount')->nullable()->after('approval_token');
            $table->timestamp('approval_requested_at')->nullable()->after('approval_quoted_amount');
            $table->timestamp('declined_at')->nullable()->after('approval_requested_at');

            // Partial-unique so the many NULLs (every ticket not awaiting approval) do
            // not collide — the same pattern the catalogue uses for barcodes.
            $table->index(['tenant_id', 'approval_token']);
        });

        DB::statement(
            'create unique index repair_tickets_approval_token_unique
             on repair_tickets (tenant_id, approval_token)
             where approval_token is not null'
        );

        DB::statement(
            "comment on column repair_tickets.approval_quoted_amount is
             'The figure the customer was SHOWN. Frozen when the link was minted so a later estimate edit cannot re-price an outstanding request.'"
        );
    }

    public function down(): void
    {
        DB::statement('drop index if exists repair_tickets_approval_token_unique');

        Schema::table('repair_tickets', function (Blueprint $table): void {
            $table->dropColumn([
                'approval_token', 'approval_quoted_amount', 'approval_requested_at', 'declined_at',
            ]);
        });
    }
};
