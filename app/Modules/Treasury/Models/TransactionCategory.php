<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Models;

use App\Modules\CRM\Models\Account;
use App\Modules\Treasury\Enums\CashDirection;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A heading money is counted under — «حقوق», «اجاره», «اجاره میز».
 *
 * Each one owns an account, which is what makes a P&L by category a set of ledger
 * balances rather than a second aggregation that has to be kept in step with the first.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $parent_id
 * @property int $account_id
 * @property string $name
 * @property CashDirection $direction
 * @property bool $is_active
 */
final class TransactionCategory extends Model
{
    use BelongsToTenant;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'parent_id', 'account_id', 'name', 'direction', 'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => CashDirection::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<TransactionCategory, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<TransactionCategory, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * «هزینه‌ها ← حقوق ← فروشنده‌ها», for a report that has to name the row.
     */
    public function path(): string
    {
        $names = [$this->name];
        $node = $this->parent;
        $guard = 0;

        // Bounded rather than trusting the data: a cycle that got past the service would
        // otherwise hang a report, and a hung report is harder to diagnose than a wrong one.
        while ($node instanceof self && $guard++ < 10) {
            array_unshift($names, $node->name);
            $node = $node->parent;
        }

        return implode(' ← ', $names);
    }
}
