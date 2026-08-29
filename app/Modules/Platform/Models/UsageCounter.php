<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much of one credit a shop has spent in one period.
 *
 * Platform-owned like {@see Subscription}: RLS-protected on `tenant_id` with the
 * `app.platform` escape, and deliberately WITHOUT `BelongsToTenant`, because the panel
 * reads across shops and the Eloquent scope adds `1 = 0` when no tenant is pinned. Every
 * query here carries its own `where('tenant_id', …)`; `bin/check-quota-scoping` enforces
 * that rather than leaving it to reviewer attention.
 *
 * **Writes do not go through this model.** `DatabaseQuotaGuard` spends a credit with one
 * raw `INSERT … ON CONFLICT DO UPDATE … WHERE`, because check-and-increment has to be a
 * single statement to be safe under concurrency — Eloquent would make it read, decide in
 * PHP, and write, which is the classic two-requests-both-see-one-left bug. This model is
 * for reading: the shared prop, the Filament usage page, the tests.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $metric
 * @property string $period_key
 * @property int $used
 * @property CarbonImmutable|null $blocked_at
 * @property CarbonImmutable|null $first_used_at
 * @property CarbonImmutable|null $last_used_at
 */
final class UsageCounter extends Model
{
    public $timestamps = false;

    protected $fillable = ['tenant_id', 'metric', 'period_key', 'used', 'blocked_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'used' => 'integer',
            'blocked_at' => 'immutable_datetime',
            'first_used_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
