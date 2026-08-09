<?php

declare(strict_types=1);

use App\Support\Database\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which branches a user may work in.
 *
 * The rule is "**no rows means every branch**", which is the opposite of how permissions
 * usually work and is deliberate. Most shops are one branch and will never assign
 * anybody to anything; making the empty case mean "nothing" would lock every user out of
 * a feature they never asked to configure.
 *
 * Restriction is therefore opt-in, and only meaningful once a shop actually has a second
 * branch — which is exactly when a manager starts caring that the Karaj cashier cannot
 * see Tehran's till.
 *
 * This scopes *visibility of data*, not *what actions are allowed*; that stays with
 * spatie/laravel-permission. A cashier restricted to Karaj still has `sales.create` —
 * they simply cannot create a sale against Tehran's stock.
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        Schema::create('branch_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['branch_id', 'user_id']);
            $table->index(['tenant_id', 'user_id']);
        });

        $this->enableRls('branch_user');
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_user');
    }
};
