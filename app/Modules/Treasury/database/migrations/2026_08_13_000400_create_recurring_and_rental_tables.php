<?php

declare(strict_types=1);

use App\Support\Database\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The things that happen every month whether anybody remembers them or not.
 *
 * Rent, wages, the internet bill — and on the income side, the desk the shop leases to a
 * repair technician or an accessories seller. Shops forget these, and a P&L missing a
 * month's rent overstates profit by more than most months actually make.
 *
 * ## The period is the identity, not the run
 *
 * A template does not have a "last run" that gets bumped. It has a set of periods, each of
 * which has either been generated or has not. `cash_transactions.generated_key` holds
 * `template:{id}:{period}` — for example `template:4:1405-06` — under a unique index.
 *
 * That is what makes the generator safe to run twice, or five times, or by two workers at
 * once: the second attempt collides on the insert and is swallowed. A `last_run_at`
 * column would be a stored position that drifts the first time a job fails halfway, and
 * the shop would find out by being billed twice or not at all. Same argument as the
 * abandoned-device sweep in Phase 6, and the same mechanism.
 *
 * It also means a template that has been switched off for three months and is switched
 * back on generates the periods it missed, or does not, according to an explicit rule
 * rather than an accident of where a pointer happened to stop.
 *
 * ## Rentals are their own table, not a template with a party
 *
 * A rental has a start and an end, a signed contract, a deposit, and a party who can be
 * chased. A recurring template has none of those and should not grow them. The contract
 * generates income transactions the same way a template does, through the same
 * period-keyed idempotency, but «قرارداد اجاره» is a document a shop shows somebody, and
 * modelling it as a settings row would make it impossible to answer "what does this desk
 * earn us and until when".
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        Schema::create('recurring_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('transaction_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('party_id')->nullable()->constrained('parties')->nullOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();

            $table->string('name');
            $table->string('direction')->comment('expense | income');
            $table->unsignedBigInteger('amount');

            // Which day of the Jalali month it falls on. Clamped to the month's length at
            // generation, so a template set to the 31st still fires in a 30-day month
            // rather than skipping it silently.
            $table->unsignedTinyInteger('day_of_month')->default(1);

            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'is_active'], 'recurring_templates_tenant_active_idx');
        });

        DB::statement(
            "alter table recurring_templates
             add constraint recurring_templates_direction
             check (direction in ('expense', 'income'))"
        );

        DB::statement(
            'alter table recurring_templates
             add constraint recurring_templates_day_of_month
             check (day_of_month between 1 and 31)'
        );

        $this->enableRls('recurring_templates');

        Schema::create('rental_contracts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            // Who is renting the space. Required — unlike an expense, there is always
            // somebody to chase for rent.
            $table->foreignId('party_id')->constrained('parties')->restrictOnDelete();

            $table->foreignId('transaction_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();

            $table->string('number')->comment('From the counters table, never MAX+1');
            $table->string('title')->comment('e.g. میز تعمیرات — گوشه شمالی');

            $table->unsignedBigInteger('monthly_amount');
            $table->unsignedBigInteger('deposit')->default(0)->comment('ودیعه — held, not income');

            $table->unsignedTinyInteger('due_day')->default(1);

            $table->date('starts_on');
            $table->date('ends_on')->nullable()->comment('null = open-ended until terminated');
            $table->date('terminated_on')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'number'], 'rental_contracts_tenant_number_unique');
            $table->index(['tenant_id', 'party_id'], 'rental_contracts_tenant_party_idx');
            $table->index(['tenant_id', 'ends_on'], 'rental_contracts_tenant_ends_idx');
        });

        DB::statement(
            'alter table rental_contracts
             add constraint rental_contracts_positive_amount
             check (monthly_amount > 0)'
        );

        // A contract cannot end before it starts. Cheap to check, and the mis-typed year
        // that makes it true would otherwise generate nothing and look like a bug in the
        // generator.
        DB::statement(
            'alter table rental_contracts
             add constraint rental_contracts_sane_period
             check (ends_on is null or ends_on >= starts_on)'
        );

        $this->enableRls('rental_contracts');

        Schema::table('cash_transactions', function (Blueprint $table): void {
            $table->foreignId('recurring_template_id')->nullable()->after('transaction_category_id')
                ->constrained('recurring_templates')->nullOnDelete();
            $table->foreignId('rental_contract_id')->nullable()->after('recurring_template_id')
                ->constrained('rental_contracts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('rental_contract_id');
            $table->dropConstrainedForeignId('recurring_template_id');
        });

        Schema::dropIfExists('rental_contracts');
        Schema::dropIfExists('recurring_templates');
    }
};
