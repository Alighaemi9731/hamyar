<?php

declare(strict_types=1);

use App\Support\Database\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A named set of report filters, per user.
 *
 * ## Per user, not per shop
 *
 * The accountant's «سه ماه گذشته، شعبه مرکزی» and the manager's «امسال، همه شعب» are two
 * people's habits, not a shop-wide setting. Sharing them would mean the last person to save
 * one rearranges everybody else's report screen — and the whole value of a preset is that it
 * is *there*, unchanged, when you open the report tomorrow morning.
 *
 * `tenant_id` is still on the row and still under RLS (golden rule 1). A user belongs to one
 * shop, but "the user id scopes it" is exactly the kind of reasoning that stops being true
 * the first time somebody adds a support-staff account, and the tenancy layer does not take
 * arguments about who can currently reach what.
 *
 * ## The filters are opaque JSON, deliberately
 *
 * Each report has a different filter shape — a range here, an as-of date there, a threshold
 * in days on dead stock — and a column per filter would need a migration every time a report
 * gained one. So the payload is JSON and the *screen* interprets it. What stops that becoming
 * a hole is the other half: a preset carries no permission of its own. Opening one navigates
 * to the report, which asks `ReportAccess` exactly as it does for a typed URL. A preset is a
 * bookmark, and a bookmark cannot grant anything.
 *
 * ## One name per report per user
 *
 * Saving «سه ماه گذشته» twice is somebody updating it, not creating a second one. Without the
 * constraint they get two rows with one name, pick the wrong one, and conclude the feature
 * does not work.
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        Schema::create('saved_filters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Which report it belongs to — `sales`, `financial`, `tax`… the screen's key,
            // not a route, so moving a route does not orphan every preset in the database.
            $table->string('report_key');

            $table->string('name');

            $table->jsonb('filters');

            $table->timestamps();

            // Leads with tenant_id (golden rule 1), then the two columns the only read
            // this table ever does filters on: this user's presets for this report.
            $table->index(['tenant_id', 'user_id', 'report_key'], 'saved_filters_tenant_user_report_idx');

            $table->unique(['tenant_id', 'user_id', 'report_key', 'name'], 'saved_filters_name_unique');
        });

        $this->enableRls('saved_filters');

        DB::statement('alter table saved_filters add constraint saved_filters_name_not_empty check (length(trim(name)) > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_filters');
    }
};
