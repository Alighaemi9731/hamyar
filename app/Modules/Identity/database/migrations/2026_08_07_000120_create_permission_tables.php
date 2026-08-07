<?php

declare(strict_types=1);

use App\Support\Database\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * spatie/laravel-permission tables, with teams mapped to tenants.
 *
 * Hand-written rather than published, because the published stub does not know about
 * our RLS requirement and the split below needs stating explicitly:
 *
 * **`permissions` and `role_has_permissions` are CENTRAL** — no `tenant_id`, no RLS.
 * The `module.action` catalogue is a property of the software, not of a shop. Every
 * tenant refers to the same `sales.create`. Duplicating it per tenant would mean
 * thousands of copies to migrate whenever a new capability ships.
 *
 * **`roles`, `model_has_roles` and `model_has_permissions` are TENANT-SCOPED** with
 * RLS. Roles are seeded per tenant at onboarding rather than shared, so a shop can
 * genuinely change what its Salesperson may do — for example granting
 * `inventory.view_cost`, which some shops want and most emphatically do not.
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->string('module')->nullable()->comment('Grouping for the permission matrix UI');
            $table->string('description_fa')->nullable();
            $table->timestamps();

            $table->unique(['name', 'guard_name']);
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('name_fa')->nullable();
            $table->string('guard_name');
            // A seeded role cannot be deleted and its name cannot change; its
            // permissions still can. Stops a shop from removing "Owner" and locking
            // itself out of its own account.
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'name', 'guard_name']);
        });

        $this->enableRls('roles');

        Schema::create('model_has_permissions', function (Blueprint $table): void {
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->index(['model_id', 'model_type'], 'model_has_permissions_model_id_model_type_index');
            $table->primary(
                ['tenant_id', 'permission_id', 'model_id', 'model_type'],
                'model_has_permissions_permission_model_type_primary'
            );
        });

        $this->enableRls('model_has_permissions');

        Schema::create('model_has_roles', function (Blueprint $table): void {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->index(['model_id', 'model_type'], 'model_has_roles_model_id_model_type_index');
            $table->primary(
                ['tenant_id', 'role_id', 'model_id', 'model_type'],
                'model_has_roles_role_model_type_primary'
            );
        });

        $this->enableRls('model_has_roles');

        Schema::create('role_has_permissions', function (Blueprint $table): void {
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();

            $table->primary(['permission_id', 'role_id'], 'role_has_permissions_permission_id_role_id_primary');
        });

        // No RLS: it has no tenant_id of its own. It is reachable only through a
        // `roles` row, which IS scoped — so a tenant can never see another tenant's
        // role/permission pairs anyway.
    }

    public function down(): void
    {
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('model_has_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
