<?php

declare(strict_types=1);

use App\Modules\Platform\Models\Plan;
use App\Modules\Platform\Models\Subscription;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The data half of DECISION GATE 6: every module open, limits keyed by metric, and no
 * subscription left in a state nothing writes.
 *
 * ## `modules.is_enabled`
 *
 * A platform kill-switch, defaulting on. It replaces `plan_module` as the thing
 * `EnsureModuleEnabled` consults — the middleware survives, its question changes from "did
 * this shop buy it" to "have we switched it on". ADR 0011 (Moadian shipped without a
 * provider) needs exactly this and has been making do without it.
 *
 * ## `plan_limits` keys become metric keys
 *
 * Three of the five old keys have a direct successor and are renamed in place, so any
 * value an operator had already tuned in the panel survives:
 *
 *   users      → identity.users
 *   branches   → inventory.branches
 *   storage_mb → files.storage_mb
 *
 * `invoices_per_month` is deleted rather than renamed to `sales.invoices`. It looks like
 * the same number and is not: it was never enforced, so every value in it is a guess
 * nobody has ever tested against a real shop, and carrying a guess forward under a name
 * that now *does* something would hide the fact that the new numbers were chosen at the
 * gate. `sales.invoices` arrives from the catalogue instead.
 *
 * `sms_credit_bonus` is deleted after being copied to `plans.sms_credit_grant_count`. It
 * was a count of free messages, which is a grant rather than a cap — a different idea that
 * happened to live in the limits table. The SMS package work in a later phase needs it;
 * losing it here would be losing the only record of what each plan was supposed to include.
 *
 * ## `trialing` subscriptions become `active` on the free plan
 *
 * The free rung replaced the trial at the gate, so nothing writes `trialing` any more. A
 * row left in that state would sit there until its `trial_ends_at` passed and then become
 * unusable — a shop silently demoted by a state machine that no longer exists. They are
 * moved now, deliberately, rather than left for `subscriptions:expire` to cancel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modules', function (Blueprint $table): void {
            $table->boolean('is_enabled')->default(true)->after('is_core');
        });

        Schema::table('plans', function (Blueprint $table): void {
            $table->unsignedInteger('sms_credit_grant_count')->default(0)->after('price');
        });

        // Carry the grant before deleting the row that holds it.
        foreach (DB::table('plan_limits')->where('key', 'sms_credit_bonus')->get() as $row) {
            DB::table('plans')->where('id', $row->plan_id)
                ->update(['sms_credit_grant_count' => (int) ($row->value ?? 0)]);
        }

        foreach ([
            'users' => 'identity.users',
            'branches' => 'inventory.branches',
            'storage_mb' => 'files.storage_mb',
        ] as $old => $new) {
            // Only where the new key is not already present, so re-running against a
            // database the seeder has already touched cannot violate (plan_id, key).
            $taken = DB::table('plan_limits')->where('key', $new)->pluck('plan_id')->all();

            DB::table('plan_limits')
                ->where('key', $old)
                ->whereNotIn('plan_id', $taken === [] ? [0] : $taken)
                ->update(['key' => $new]);

            DB::table('plan_limits')->where('key', $old)->delete();
        }

        DB::table('plan_limits')->whereIn('key', ['invoices_per_month', 'sms_credit_bonus'])->delete();

        $this->moveTrialsToFreePlan();
    }

    public function down(): void
    {
        foreach ([
            'identity.users' => 'users',
            'inventory.branches' => 'branches',
            'files.storage_mb' => 'storage_mb',
        ] as $new => $old) {
            DB::table('plan_limits')->where('key', $new)->update(['key' => $old]);
        }

        // Metric-keyed rows the catalogue introduced have no pre-Phase-12 equivalent;
        // dropping them is the honest reverse, since nothing before this release read them.
        DB::table('plan_limits')->where('key', 'like', '%.%')->delete();

        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn('sms_credit_grant_count');
        });

        Schema::table('modules', function (Blueprint $table): void {
            $table->dropColumn('is_enabled');
        });
    }

    /**
     * A trial has nowhere to end now, so end it on the free plan.
     */
    private function moveTrialsToFreePlan(): void
    {
        $free = Plan::query()->where('price', 0)->orderBy('position')->first();

        if (! $free instanceof Plan) {
            // A database with no free plan yet — a fresh install mid-seed. Nothing to
            // move, and inventing a plan here would seed business data from a migration.
            return;
        }

        DB::table('subscriptions')
            ->where('status', Subscription::STATUS_TRIALING)
            ->update([
                'status' => Subscription::STATUS_ACTIVE,
                'plan_id' => $free->getKey(),
                'trial_ends_at' => null,
                // A free subscription has no period to end; `Subscription::isUsable()`
                // reads a null `current_period_end` on an active row as "usable".
                'current_period_end' => null,
                'plan_changed_at' => now(),
            ]);
    }
};
