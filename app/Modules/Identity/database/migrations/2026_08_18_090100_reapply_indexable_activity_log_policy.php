<?php

declare(strict_types=1);

use App\Support\Database\EnablesRowLevelSecurity;
use Illuminate\Database\Migrations\Migration;

/**
 * Re-apply the `activity_log` isolation policy in its indexable form.
 *
 * The policy was written with `tenant_id IS NOT DISTINCT FROM <current>` — the one
 * operator that says "equal, and NULL counts as equal to NULL", which is exactly the
 * semantics a table holding both tenant and central rows needs.
 *
 * **`IS NOT DISTINCT FROM` cannot use a btree index.** An RLS predicate is ANDed into
 * every query against the table, so the whole table had no usable index at all: the
 * `(tenant_id, created_at)` index added with the column, and both indexes added for
 * this viewer's filters, were dead the moment they were created. Nothing reported an
 * error; the audit log simply scanned the entire platform to answer a question about
 * one shop, and did it a little slower every time a shop was added.
 *
 * Found by measuring rather than by reading, which is the only way this shows up —
 * `EXPLAIN` on a seeded 1.8M-row table (fifty shops, a year of history):
 *
 * | predicate                     | plan                    | time     |
 * |-------------------------------|-------------------------|----------|
 * | `IS NOT DISTINCT FROM`        | Parallel Seq Scan       | 55.8 ms  |
 * | `= x OR (x IS NULL AND …)`    | Index Scan Backward     | 0.155 ms |
 *
 * {@see EnablesRowLevelSecurity} now emits the OR form, so a
 * fresh migration produces it. This migration is for databases that already ran the
 * old one. The semantics are unchanged — same rows visible, same rows writable — which
 * is why it is a re-apply and not a change of policy.
 */
return new class extends Migration
{
    use EnablesRowLevelSecurity;

    public function up(): void
    {
        // `enableRls()` drops and recreates the policy by name, so calling it again
        // with the same arguments is the whole migration.
        $this->enableRls('activity_log', allowNullTenant: true);
    }

    public function down(): void
    {
        // Deliberately not a restoration of the slow predicate. `down()` exists to
        // undo a schema change, and this migration changed no schema — reinstating a
        // policy that disables every index would be a rollback into a performance
        // defect. The policy this leaves behind is correct in both directions.
        $this->enableRls('activity_log', allowNullTenant: true);
    }
};
