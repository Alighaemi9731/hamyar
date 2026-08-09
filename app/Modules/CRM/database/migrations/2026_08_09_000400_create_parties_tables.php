<?php

declare(strict_types=1);

use App\Support\Database\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Everyone the shop owes money to, or is owed money by.
 *
 * One table for customers, suppliers and reseller colleagues, because in this trade one
 * person is routinely all three: the همکار who buys ten handsets on Sunday sells you a
 * trade-in on Tuesday and brings his own phone for repair on Thursday. Splitting them
 * into three tables means three balances for one human being, and the shop then has to
 * do the netting in their head.
 *
 * `kind` records what they mostly are, for filtering and defaults. It deliberately does
 * NOT restrict what they can do — a party marked `customer` can still appear on a
 * purchase invoice, because the alternative is a data-entry dead end at the counter.
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        Schema::create('parties', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('kind')->default('customer')->comment('customer | supplier | colleague | both');

            $table->string('name');
            $table->string('company_name')->nullable();

            // Iranian tax identifiers. Needed on a Moadian invoice (Phase 10), so they
            // are captured from the start rather than chased retrospectively.
            $table->string('national_id', 11)->nullable();
            $table->string('economic_code', 20)->nullable();

            // Which price tier this party buys at. Null means the shop default, so a
            // walk-in needs no setup.
            $table->foreignId('price_level_id')->nullable()->constrained()->nullOnDelete();

            // Rial. Zero means "no credit" — a real answer, distinct from null meaning
            // "nobody has decided", which is why this is nullable rather than defaulted.
            $table->unsignedBigInteger('credit_limit')->nullable();

            // Signed: positive = they owe the shop, negative = the shop owes them. This
            // is the ONLY balance figure stored anywhere, and it is a starting point
            // carried in from whatever the shop used before — never a running total.
            $table->bigInteger('opening_balance')->default(0);

            // Stored UTC, entered as Jalali (golden rule 5). Used for birthday SMS.
            $table->date('birthday')->nullable();

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'kind']);
            $table->index(['tenant_id', 'is_active']);
            $table->index(['tenant_id', 'name']);
        });

        $this->enableRls('parties');

        // National ID identifies a person uniquely in Iran, so a duplicate is almost
        // always the same human entered twice — which splits their balance in half and
        // makes the statement wrong. Partial: most walk-in customers never give one.
        DB::statement(
            'create unique index parties_national_id_unique
             on parties (tenant_id, national_id)
             where national_id is not null and deleted_at is null'
        );

        Schema::create('party_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->constrained()->cascadeOnDelete();

            $table->string('type')->default('mobile')->comment('mobile | phone | email');
            $table->string('value');
            $table->string('label')->nullable()->comment('e.g. "همراه دوم", "دفتر"');

            $table->boolean('is_primary')->default(false);

            $table->timestamps();

            $table->index(['tenant_id', 'party_id']);
            // The lookup that matters: the counter searches by phone number constantly.
            $table->index(['tenant_id', 'value']);
        });

        $this->enableRls('party_contacts');

        DB::statement(
            'create unique index party_contacts_one_primary_per_party
             on party_contacts (party_id, type) where is_primary'
        );

        Schema::create('party_addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->constrained()->cascadeOnDelete();

            $table->string('label')->nullable();
            $table->string('province')->nullable();
            $table->string('city')->nullable();
            $table->text('line')->nullable();
            $table->string('postal_code', 10)->nullable();

            $table->boolean('is_primary')->default(false);

            $table->timestamps();

            $table->index(['tenant_id', 'party_id']);
        });

        $this->enableRls('party_addresses');

        Schema::create('party_tags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('colour', 20)->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        $this->enableRls('party_tags');

        Schema::create('party_tag_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_tag_id')->constrained()->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['party_id', 'party_tag_id']);
            $table->index(['tenant_id', 'party_tag_id']);
        });

        $this->enableRls('party_tag_assignments');

        // The FK that has been waiting since Phase 3.3. `product_units` shipped with an
        // unconstrained bigint because parties did not exist yet; now they do, and
        // "bought from whom" is half of the IMEI passport.
        Schema::table('product_units', function (Blueprint $table): void {
            $table->foreign('acquired_from_party_id')->references('id')->on('parties')->nullOnDelete();
            $table->index(['tenant_id', 'acquired_from_party_id']);
        });
    }

    public function down(): void
    {
        Schema::table('product_units', function (Blueprint $table): void {
            $table->dropForeign(['acquired_from_party_id']);
            $table->dropIndex(['tenant_id', 'acquired_from_party_id']);
        });

        Schema::dropIfExists('party_tag_assignments');
        Schema::dropIfExists('party_tags');
        Schema::dropIfExists('party_addresses');
        Schema::dropIfExists('party_contacts');
        Schema::dropIfExists('parties');
    }
};
