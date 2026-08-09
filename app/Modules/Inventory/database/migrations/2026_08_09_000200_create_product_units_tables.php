<?php

declare(strict_types=1);

use App\Support\Database\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The serialized register — one row per physical handset.
 *
 * This table and its history are the product's biggest differentiator. Together they
 * answer, for any device the shop has ever touched: **bought from whom → sold to whom →
 * repaired when**. No competitor in this market does it well, and it is the reason a
 * shop with a working IMEI passport does not switch away.
 *
 * `cost` is per unit and never averaged. Two identical handsets bought a week apart at
 * different prices are two rows with two costs, because profit on a phone is calculated
 * on the actual device sold — averaging destroys the only number the shop cares about.
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        Schema::create('product_units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();

            // Normalised to 15 Latin digits before storage, so a number typed with
            // Persian digits matches one scanned from a box.
            $table->string('imei1', 15)->nullable();
            $table->string('imei2', 15)->nullable();

            // Devices that genuinely have no IMEI — smartwatches, some tablets.
            $table->string('serial')->nullable();

            $table->string('status')->default('in_stock');
            $table->string('condition')->default('new');
            $table->string('grade', 2)->nullable()->comment('A | B | C — cosmetic, used stock only');

            $table->unsignedBigInteger('cost')->default(0)->comment('THIS unit, integer rial — never averaged');

            // Supplier, or the customer on a trade-in. Parties arrive in Phase 4, so the
            // FK is added then rather than pointing at a table that does not exist.
            $table->unsignedBigInteger('acquired_from_party_id')->nullable();
            $table->timestamp('acquired_at')->nullable();

            $table->string('hamta_status')->default('not_required')
                ->comment('not_required | pending | done — recorded, never verified (no API)');
            $table->string('hamta_activation_id')->nullable();

            $table->unsignedSmallInteger('warranty_months')->nullable();
            $table->timestamp('warranty_until')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'warehouse_id', 'status']);
            $table->index(['tenant_id', 'product_variant_id', 'status']);
        });

        $this->enableRls('product_units');

        // Per-column uniqueness, partial on live rows: a soft-deleted write-off must not
        // block re-registering the same handset if it comes back through the door.
        DB::statement(
            'create unique index product_units_imei1_unique
             on product_units (tenant_id, imei1)
             where imei1 is not null and deleted_at is null'
        );

        DB::statement(
            'create unique index product_units_imei2_unique
             on product_units (tenant_id, imei2)
             where imei2 is not null and deleted_at is null'
        );

        DB::statement(
            'create unique index product_units_serial_unique
             on product_units (tenant_id, serial)
             where serial is not null and deleted_at is null'
        );

        // Those two indexes are NOT enough on their own. They stop the same number
        // appearing twice in the same column, but a dual-SIM phone's `imei2` could still
        // be registered as a different device's `imei1` — which is the same physical
        // handset entered twice, and exactly what the IMEI passport must never allow.
        //
        // Postgres cannot express "unique across two columns of the same table" as an
        // index, so a trigger does it. It runs per row on write only; IMEIs are written
        // at intake and rarely edited, so this is not on any hot path.
        DB::unprepared(<<<'SQL'
            create or replace function product_units_imei_unique() returns trigger as $$
            declare
                conflicting bigint;
            begin
                select id into conflicting
                from product_units
                where tenant_id = new.tenant_id
                  and id is distinct from new.id
                  and deleted_at is null
                  and (
                      (new.imei1 is not null and new.imei1 in (imei1, imei2))
                      or (new.imei2 is not null and new.imei2 in (imei1, imei2))
                  )
                limit 1;

                if conflicting is not null then
                    raise exception 'IMEI already registered to product_unit %', conflicting
                        using errcode = 'unique_violation';
                end if;

                return new;
            end;
            $$ language plpgsql;
        SQL);

        DB::unprepared(
            'create trigger product_units_imei_unique_trigger
             before insert or update of imei1, imei2, deleted_at on product_units
             for each row execute function product_units_imei_unique();'
        );

        Schema::create('product_unit_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_unit_id')->constrained()->cascadeOnDelete();

            $table->string('from_status')->nullable()->comment('Null on the first entry — acquisition');
            $table->string('to_status');

            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            // The document that caused it: a purchase invoice, a sale, a repair ticket.
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->text('note')->nullable();

            // Append-only, so `updated_at` would be a lie. Only the creation instant
            // exists, and nothing may ever change it.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'product_unit_id', 'id']);
            $table->index(['reference_type', 'reference_id']);
        });

        $this->enableRls('product_unit_histories');
    }

    public function down(): void
    {
        DB::unprepared('drop trigger if exists product_units_imei_unique_trigger on product_units');
        DB::unprepared('drop function if exists product_units_imei_unique()');

        Schema::dropIfExists('product_unit_histories');
        Schema::dropIfExists('product_units');
    }
};
