<?php

declare(strict_types=1);

namespace App\Modules\Installments\Models;

use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One instalment: «قسط سوم، سررسید ۱۵ مرداد».
 *
 * Carries no `paid_amount`. Collection lands in Phase 7.4 with its own receipts, and
 * what a row has been paid is a SUM over those (golden rule 3) — a counter here would be
 * a second truth about the same money.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $installment_plan_id
 * @property int $sequence
 * @property CarbonImmutable $due_at
 * @property int $amount
 * @property string $status
 * @property CarbonImmutable|null $settled_at
 */
final class InstallmentRow extends Model
{
    use BelongsToTenant;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_OVERDUE = 'overdue';

    /** @var array<string, mixed> */
    protected $attributes = ['status' => self::STATUS_PENDING];

    protected $fillable = [
        'tenant_id', 'installment_plan_id', 'sequence', 'due_at', 'amount', 'status', 'settled_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_at' => 'immutable_datetime',
            'settled_at' => 'immutable_datetime',
            'sequence' => 'integer',
            'amount' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<InstallmentPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(InstallmentPlan::class, 'installment_plan_id');
    }
}
