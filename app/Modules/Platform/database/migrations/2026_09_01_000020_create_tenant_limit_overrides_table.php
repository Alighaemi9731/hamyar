<?php

declare(strict_types=1);

use App\Support\Database\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A limit negotiated for one shop, overriding whatever its plan says.
 *
 * Every B2B product needs this and the ones that do not build it end up expressing it as
 * a secret plan nobody can find later. Here it is one row with a reason on it: support
 * raises a cap for a shop mid-migration, a large customer buys the third rung and needs
 * fifty seats instead of twenty-five, a goodwill gesture after an outage.
 *
 * `value` null means unlimited **for this shop**, which is the whole point — an override
 * that could only lower a limit would be a punishment feature.
 *
 * `reason` is `NOT NULL` and required in the panel. An override with no reason is
 * indistinguishable from a mistake six months later, and the person who has to decide
 * whether to remove it will not be the person who added it.
 *
 * `expires_at` makes a temporary raise actually temporary. Without it every override is
 * permanent, because nobody comes back to tidy up a limit that is not hurting anyone.
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        Schema::create('tenant_limit_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('metric', 48);

            // Nullable = unlimited for this shop. Same meaning as `plan_limits.value`,
            // deliberately: two columns for the same idea must not disagree about what
            // null means.
            $table->unsignedBigInteger('value')->nullable();

            $table->string('reason', 200);
            $table->timestampTz('expires_at')->nullable();

            // Who granted it. Null when the platform user is later deleted — the override
            // outlives the staff member, and losing the row would silently re-cap a shop.
            $table->foreignId('created_by')->nullable()
                ->constrained('platform_users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['tenant_id', 'metric'], 'tenant_limit_overrides_scope_unique');
        });

        $this->enableRls('tenant_limit_overrides', allowPlatform: true);
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_limit_overrides');
    }
};
