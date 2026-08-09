<?php

declare(strict_types=1);

use App\Support\Database\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where a shop physically is, and where its stock physically sits.
 *
 * Two levels, not one, because they answer different questions. A **branch** is a place
 * customers walk into: it has an address, a phone number, its own invoice numbering and
 * its own staff. A **warehouse** is a place stock sits, and one branch routinely has
 * several — shop floor, back room, repair bench. Collapsing them would make "how many
 * are on the shelf versus behind the counter" unanswerable, which is the question that
 * decides whether a sale can happen.
 *
 * Every shop gets one of each at provisioning, so single-branch shops — most of them —
 * never see the concept.
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('name');

            // Short prefix for document numbers: TEH-1405-000042. Per tenant, not global.
            $table->string('code', 10);

            $table->string('phone')->nullable();
            $table->text('address')->nullable();

            // Exactly one per tenant, enforced by the partial unique index below.
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'is_active']);
            $table->unique(['tenant_id', 'code']);
        });

        $this->enableRls('branches');

        // "At most one default" as a database rule rather than application etiquette.
        // A partial index is the only way to say it: a plain unique on
        // (tenant_id, is_default) would also forbid a second NON-default branch.
        $this->oneDefaultPerScope('branches', 'tenant_id', 'branches_one_default_per_tenant');

        Schema::create('warehouses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();

            $table->string('name');

            // Stock here is sellable. A repair bench holds parts that are committed to a
            // job and must not appear as available to the till.
            $table->boolean('is_sellable')->default(true);

            // Some shops genuinely sell ahead of stock arriving; off by default because
            // silently allowing negative stock hides counting errors for months.
            $table->boolean('allows_negative_stock')->default(false);

            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'branch_id']);
            $table->index(['tenant_id', 'is_active']);
        });

        $this->enableRls('warehouses');

        $this->oneDefaultPerScope('warehouses', 'branch_id', 'warehouses_one_default_per_branch');
    }

    /**
     * "At most one default per scope", as a database rule rather than application
     * etiquette.
     *
     * A partial index is the only way to say it. A plain unique on
     * `(scope, is_default)` would also forbid a second NON-default row, which is the
     * opposite of what is wanted.
     */
    private function oneDefaultPerScope(string $table, string $scope, string $indexName): void
    {
        DB::statement(
            "create unique index {$indexName} on {$table} ({$scope})
             where is_default and deleted_at is null"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('branches');
    }
};
