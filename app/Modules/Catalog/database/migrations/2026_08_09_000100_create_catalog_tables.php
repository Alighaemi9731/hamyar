<?php

declare(strict_types=1);

use App\Support\Database\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the shop sells.
 *
 * Catalog says a thing *exists and costs this much*; Inventory says *where it is and
 * what happened to it*. Keeping them apart is what lets a phone model be one catalogue
 * row while the twelve physical handsets are twelve `product_units`.
 *
 * `products.type` is the fork in the road for the entire system:
 *
 * - `standard` — accessories and parts. A quantity in a warehouse, weighted-average cost.
 * - `serialized` — phones. One row per device in `product_units`, each with its own
 *   purchase cost, because profit on a handset is per-unit and averaging it destroys the
 *   only number the shop actually cares about.
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Self-referencing tree. Shallow in practice (گوشی موبایل › اپل), so an
            // adjacency list is enough — nested sets would be machinery for a depth
            // nobody reaches.
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();

            $table->string('name');
            $table->string('slug');
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'parent_id']);
            $table->unique(['tenant_id', 'slug']);
        });

        $this->enableRls('categories');

        Schema::create('brands', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Both spellings: the Latin name is what a supplier invoice says and what
            // people type when searching; the Persian one is what the UI shows.
            $table->string('name');
            $table->string('name_fa')->nullable();

            $table->unsignedInteger('position')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'position']);
            $table->unique(['tenant_id', 'name']);
        });

        $this->enableRls('brands');

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->string('sku')->nullable();

            $table->string('type')->default('standard')->comment('standard | serialized');

            $table->text('description')->nullable();

            // Per product, not global: a shop wants to be told at 2 chargers but not at
            // 2 flagship handsets, which is normal stock for those.
            $table->unsignedInteger('low_stock_threshold')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'type']);
            $table->index(['tenant_id', 'is_active']);
            $table->index(['tenant_id', 'brand_id']);
        });

        $this->enableRls('products');

        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            // The attribute matrix that produced this row: {"colour":"مشکی","storage":"256"}.
            // JSONB rather than columns because the attribute set differs by category —
            // a phone has storage and RAM, a case has neither.
            //
            // Named `options`, NOT `attributes`: Eloquent already uses `$attributes` for
            // a model's raw column values, so `$variant->attributes` would silently
            // return that array instead of this column.
            $table->jsonb('options')->default('{}');

            $table->string('name')->nullable()->comment('Display override; derived from attributes when null');

            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'product_id']);
        });

        $this->enableRls('product_variants');

        // Barcodes and SKUs are unique per tenant, but only among LIVE rows: a
        // soft-deleted variant must not hold its barcode hostage, and a shop
        // legitimately reuses a code after retiring a line. Partial indexes say exactly
        // that; a plain unique would also collide on the many NULLs.
        DB::statement(
            'create unique index product_variants_barcode_unique
             on product_variants (tenant_id, barcode)
             where barcode is not null and deleted_at is null'
        );

        DB::statement(
            'create unique index product_variants_sku_unique
             on product_variants (tenant_id, sku)
             where sku is not null and deleted_at is null'
        );

        Schema::create('price_levels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('code')->comment('consumer | reseller | vip | …');
            $table->string('name_fa');

            // Exactly one, enforced below: the level a walk-in customer gets when the
            // party has none set.
            $table->boolean('is_default')->default(false);

            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        $this->enableRls('price_levels');

        DB::statement(
            'create unique index price_levels_one_default_per_tenant
             on price_levels (tenant_id) where is_default'
        );

        Schema::create('product_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('price_level_id')->constrained()->cascadeOnDelete();

            $table->unsignedBigInteger('price')->comment('Integer RIAL (golden rule 2)');

            // Append-only history rather than an updated column: profit reports for last
            // month must use last month's price, and Iranian prices move weekly.
            $table->timestamp('effective_from');

            $table->timestamps();

            $table->index(['tenant_id', 'product_variant_id', 'price_level_id', 'effective_from'], 'product_prices_lookup');
        });

        $this->enableRls('product_prices');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_prices');
        Schema::dropIfExists('price_levels');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('categories');
    }
};
