<?php

declare(strict_types=1);

use App\Support\Database\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The three things a shop does with a customer between transactions: writes something
 * down, promises to call them back, and rewards them for coming back.
 *
 * ## Notes are append-only
 *
 * `parties.notes` already exists and stays — it is the standing description of who this
 * person is ("همیشه نقد می‌خرد", "برادر آقای کریمی"). This table is different: it is
 * what happened, dated, by whom. Editing a dated note is how a record of a conversation
 * becomes a record of what someone wishes had been said, so there is no `updated_at`
 * and nothing updates these rows.
 *
 * ## Follow-ups are the only mutable row here
 *
 * A reminder is a piece of work: it gets assigned, it gets done, it gets reassigned.
 * `done_at` rather than a boolean, because "when was this dealt with" is the question
 * that actually gets asked, and a boolean cannot answer it.
 *
 * ## Loyalty points are a ledger, not a balance
 *
 * Golden rule 3, applied to points. `loyalty_entries` is append-only and the balance is
 * `SUM(points)`; a stored total drifts, and a customer arguing about their points with
 * a shop that cannot show the arithmetic is worse than having no scheme at all.
 * Expiry is a negative entry, never a deletion, so an expired point can still be
 * explained to the person who earned it.
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        Schema::create('party_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->constrained()->cascadeOnDelete();

            $table->text('body');
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();

            // Append-only: a dated note that can be edited is not a record of what was
            // said, it is a record of what someone later wished had been said.
            $table->timestamp('created_at')->useCurrent();

            // Leads with tenant_id and lands on the query the party page makes:
            // this party's notes, newest first.
            $table->index(['tenant_id', 'party_id', 'created_at']);
        });

        $this->enableRls('party_notes');

        Schema::create('party_follow_ups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->text('body')->nullable();

            // Stored UTC, entered and displayed as Jalali (golden rule 5).
            $table->timestamp('due_at');

            // Null means nobody in particular owes this — a shop-wide reminder. Better
            // than defaulting to whoever typed it, which is how a list of tasks becomes
            // one person's list of tasks.
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // A timestamp, not a boolean: "when was this dealt with" is the question
            // that gets asked, and a boolean cannot answer it.
            $table->timestamp('done_at')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['tenant_id', 'party_id']);
            // The follow-up desk: everything still open, soonest first.
            $table->index(['tenant_id', 'done_at', 'due_at']);
            $table->index(['tenant_id', 'assignee_id', 'done_at']);
        });

        $this->enableRls('party_follow_ups');

        Schema::create('loyalty_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('name');

            // "One point per N rial spent." Integer rial in, integer points out — no
            // float anywhere near it (golden rule 2).
            $table->unsignedBigInteger('rial_per_point');

            // Null = points never expire. Zero would mean "expire immediately", which
            // is a different and legitimate answer, so this cannot be defaulted to 0.
            $table->unsignedSmallInteger('expires_after_months')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });

        $this->enableRls('loyalty_rules');

        // One active rule per shop. The earn calculation has to have exactly one answer,
        // and "whichever row came back first" is not an answer.
        DB::statement(
            'create unique index loyalty_rules_one_active_per_tenant
             on loyalty_rules (tenant_id) where is_active'
        );

        Schema::create('loyalty_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->constrained()->cascadeOnDelete();

            // Signed: positive earns, negative redeems or expires. A balance is the SUM.
            $table->integer('points');

            $table->string('reason')->comment('earn | redeem | expire | manual');
            $table->text('description')->nullable();

            // What caused it — a sale, later a campaign. Polymorphic for the same reason
            // stock movements are: any balance must be explainable line by line.
            $table->nullableMorphs('reference');

            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at')->useCurrent();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'party_id', 'occurred_at']);
        });

        $this->enableRls('loyalty_entries');

        // A zero-point entry explains nothing and would only pad the statement.
        DB::statement('alter table loyalty_entries add constraint loyalty_entries_points_not_zero check (points <> 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_entries');
        Schema::dropIfExists('loyalty_rules');
        Schema::dropIfExists('party_follow_ups');
        Schema::dropIfExists('party_notes');
    }
};
