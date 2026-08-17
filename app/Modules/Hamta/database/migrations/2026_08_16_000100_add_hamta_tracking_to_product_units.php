<?php

declare(strict_types=1);

use App\Support\Database\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * همتا — the record a shop keeps because the registry gives it nothing back.
 *
 * ## Two columns existed and nothing ever wrote to them
 *
 * `product_units.hamta_status` and `hamta_activation_id` were added in Phase 3 in
 * anticipation of this module. Between then and now **no code path set either one**: every
 * handset in every shop carried `not_required`, including the used ones, and the warnings
 * that were supposed to depend on it had nothing to depend on.
 *
 * That is the pattern `docs/testing.md` names as *"a feature with enforcement but no write
 * path is invisible"* — found in `branch_user` during the 10.1 audit and, one module later,
 * here. The columns are not new; the writers are.
 *
 * ## This module owns no register of its own
 *
 * HAMTA is a status on a device, not an entity. A `hamta_transfers` table would be a second
 * place a device's ownership state lives, and the two would disagree the first time somebody
 * corrected one — so the state stays on `product_units` and this module owns only the
 * *evidence*: which checklist steps a person confirmed, and when.
 *
 * ## `hamta_checklist_answers` is append-only, and that is the point of it
 *
 * The spec's sentence is "that record is what protects the shop in a dispute". A row is
 * somebody asserting, at a timestamp, that they checked the seller's ID or watched the
 * confirmation SMS arrive. Editing it later would make it worthless as evidence, so answers
 * are inserted and never updated — a corrected answer is a new row, and both are shown.
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        Schema::table('product_units', function (Blueprint $table): void {
            // When the transfer was confirmed. Distinct from `hamta_activation_id`
            // being present: a shop can record that a transfer happened before the
            // customer forwards the SMS with the id in it.
            $table->timestampTz('hamta_transferred_at')->nullable()->after('hamta_activation_id');

            // Free text for the case the checklist does not cover — «مالک قبلی خارج از
            // کشور است، وکالت‌نامه گرفته شد».
            $table->text('hamta_note')->nullable()->after('hamta_transferred_at');

            $table->foreignId('hamta_actor_id')->nullable()->after('hamta_note')
                ->constrained('users')->nullOnDelete();
        });

        /*
        | The pending list is the screen somebody has to clear, so it is the one query that
        | must not scan every handset the shop has ever owned. Partial on the status that
        | actually gets read: `not_required` is the overwhelming majority of rows and no
        | screen ever asks for it.
        */
        DB::statement(
            "create index product_units_hamta_pending
             on product_units (tenant_id, hamta_status)
             where hamta_status <> 'not_required' and deleted_at is null"
        );

        Schema::create('hamta_checklist_answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_unit_id')->constrained()->cascadeOnDelete();

            // Which of the six steps. A string rather than an FK to a steps table: the
            // steps are a specification, not shop data, and a shop cannot add one.
            $table->string('step');

            // Deliberately not a boolean. «تأیید شد» and «قابل انجام نبود» are different
            // answers and a dispute turns on which one it was.
            $table->string('answer')->comment('confirmed | skipped');

            $table->text('note')->nullable();

            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('answered_at');

            // No `updated_at`: these rows are evidence and are never edited. A correction
            // is a new row, and the panel shows both.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'product_unit_id'], 'hamta_answers_tenant_unit_idx');
        });

        $this->enableRls('hamta_checklist_answers');

        DB::statement(
            "alter table hamta_checklist_answers
             add constraint hamta_answers_answer
             check (answer in ('confirmed', 'skipped'))"
        );

        // The three the spec names, and nothing else. A typo in a listener that invented a
        // fourth status would otherwise surface as a warning that never appears.
        DB::statement(
            "alter table product_units
             add constraint product_units_hamta_status
             check (hamta_status in ('not_required', 'pending', 'done'))"
        );
    }

    public function down(): void
    {
        DB::statement('alter table product_units drop constraint if exists product_units_hamta_status');
        DB::statement('drop index if exists product_units_hamta_pending');

        Schema::dropIfExists('hamta_checklist_answers');

        Schema::table('product_units', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('hamta_actor_id');
            $table->dropColumn(['hamta_transferred_at', 'hamta_note']);
        });
    }
};
