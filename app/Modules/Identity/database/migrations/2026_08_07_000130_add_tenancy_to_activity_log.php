<?php

declare(strict_types=1);

use App\Support\Database\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scope the audit trail to tenants.
 *
 * spatie's published table has no tenancy, and an audit log that any shop can read is
 * worse than no audit log — it records who did what and when, across every customer.
 *
 * `tenant_id` is **nullable** because the same table also records central actions
 * (a platform admin suspending a shop, an impersonation starting). The policy
 * therefore uses the null-tolerant variant: in a tenant context you see that tenant's
 * entries; with no context you see only the central ones. Neither can see the other.
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->cascadeOnDelete();

            $table->index(['tenant_id', 'created_at']);
        });

        $this->enableRls('activity_log', allowNullTenant: true);
    }

    public function down(): void
    {
        $this->disableRls('activity_log');

        Schema::table('activity_log', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('tenant_id');
        });
    }
};
