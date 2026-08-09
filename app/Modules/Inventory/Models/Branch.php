<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A place customers walk into.
 *
 * Carries its own document numbering (see `counters.branch_id`) and its own print
 * header, because a shop with two locations issues two contiguous invoice sequences and
 * prints two addresses.
 *
 * Soft-deleted rather than removed: a branch is referenced by every invoice, movement
 * and repair ticket it ever produced, and those documents must keep saying where they
 * happened.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string $code
 * @property string|null $phone
 * @property string|null $address
 * @property bool $is_default
 * @property bool $is_active
 */
final class Branch extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\BranchFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = ['tenant_id', 'name', 'code', 'phone', 'address', 'is_default', 'is_active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    /**
     * @return HasMany<Warehouse, $this>
     */
    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    /**
     * Users restricted to this branch.
     *
     * The relation lives here rather than on User so Identity stays unaware of
     * Inventory. Read it through {@see \App\Modules\Inventory\Services\BranchAccess},
     * which owns the "no rows means every branch" rule.
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        // `withPivotValue` fills tenant_id on every attach and adds it to the join.
        // Without it a plain `attach()` writes a row with no tenant and RLS rejects it —
        // correctly, but with an error nobody would connect to a missing pivot column.
        // Taken from the branch rather than TenantContext so the relation works in a
        // queued job or a console command with no ambient context.
        return $this->belongsToMany(User::class, 'branch_user')
            ->withPivotValue('tenant_id', $this->tenant_id)
            ->withTimestamps();
    }

    /**
     * @param  Builder<Branch>  $query
     * @return Builder<Branch>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
