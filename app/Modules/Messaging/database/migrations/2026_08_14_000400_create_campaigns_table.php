<?php

declare(strict_types=1);

use App\Support\Database\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bulk sends — «به همه مشتری‌های آیفون‌دار تخفیف بده».
 *
 * ## The audience is stored as filters, not as a list of numbers
 *
 * A campaign built at 9am and sent at 6pm should reach the customers who match at 6pm, not
 * a frozen snapshot from the morning — and more importantly, a frozen list cannot honour an
 * opt-out recorded in between. Storing the filter means the audience is resolved at send
 * time, through the same door every message uses.
 *
 * The trade is that a shop cannot see exactly who will receive it until it sends. So the
 * builder shows a live count and a sample, which is what a shop actually checks.
 *
 * ## Throttling is a column, not a config constant
 *
 * Gateways rate-limit, and the limit differs per account and per contract. A shop sending
 * four thousand messages at once gets throttled by Kavenegar, retried by Horizon, and
 * charged for the retries. `per_minute` is per-campaign because the right number is a fact
 * about the shop's gateway account, not about this software.
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->text('body');
            $table->string('provider_template_id')->nullable();

            /**
             * The audience, as filters. Resolved at send time — see the class docblock on
             * why a frozen list cannot honour an opt-out recorded after it was built.
             */
            $table->jsonb('filters')->nullable();

            $table->string('status')->default('draft')->comment('draft | scheduled | sending | sent | cancelled');

            $table->unsignedInteger('per_minute')->default(60);

            $table->timestampTz('scheduled_for')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();

            $table->unsignedInteger('queued_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);

            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'campaigns_tenant_status_idx');
        });

        DB::statement(
            "alter table campaigns add constraint campaigns_status
             check (status in ('draft', 'scheduled', 'sending', 'sent', 'cancelled'))"
        );

        $this->enableRls('campaigns');

        Schema::table('messages', function (Blueprint $table): void {
            $table->foreignId('campaign_id')->nullable()->after('template_key')
                ->constrained('campaigns')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('campaign_id');
        });

        Schema::dropIfExists('campaigns');
    }
};
