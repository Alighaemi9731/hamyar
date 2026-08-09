<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Somewhere money sits: a cash drawer, a bank account, a کارتخوان.
 *
 * The minimal version. Full Treasury — transfers between accounts, reconciliation,
 * cheque handling — lands in Phase 7; this exists so Phase 3.5 purchasing and Phase 5
 * sales have somewhere to post the other side of an entry.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $branch_id
 * @property string $name
 * @property string $type
 * @property string|null $bank_name
 * @property string|null $account_number
 * @property string|null $iban
 * @property string|null $terminal_number
 * @property int $opening_balance
 * @property bool $is_default
 * @property bool $is_active
 */
final class Account extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\AccountFactory> */
    use HasFactory;

    use SoftDeletes;

    public const TYPE_CASH = 'cash';

    public const TYPE_BANK = 'bank';

    /** کارتخوان — a card reader settles to a bank account, usually next day. */
    public const TYPE_POS_TERMINAL = 'pos_terminal';

    /**
     * The value of goods on the shelf.
     *
     * Not somewhere money sits, but somewhere value does — receiving stock has to debit
     * *something*, and without this the entry would have to net against the supplier and
     * record nothing. A placeholder for the real chart of accounts in Phase 7, kept
     * deliberately singular so it cannot be mistaken for one.
     */
    public const TYPE_INVENTORY = 'inventory';

    protected $fillable = [
        'tenant_id', 'branch_id', 'name', 'type', 'bank_name', 'account_number',
        'iban', 'terminal_number', 'opening_balance', 'is_default', 'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opening_balance' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<LedgerEntry, $this>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
