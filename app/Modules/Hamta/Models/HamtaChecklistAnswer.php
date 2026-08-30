<?php

declare(strict_types=1);

namespace App\Modules\Hamta\Models;

use App\Modules\Hamta\Enums\ChecklistStep;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\ProductUnit;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's answer to one checklist step, at one moment.
 *
 * Append-only: `$timestamps` is off for `updated_at` because there is no update. A
 * correction is a new row and the panel shows the history, which is what makes the record
 * worth anything in a dispute — an answer that could be edited afterwards proves only what
 * somebody wanted it to say later.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $product_unit_id
 * @property ChecklistStep $step
 * @property string $answer
 * @property string|null $note
 * @property int|null $actor_id
 * @property CarbonImmutable $answered_at
 */
final class HamtaChecklistAnswer extends Model
{
    use BelongsToTenant;

    public const ANSWER_CONFIRMED = 'confirmed';

    public const ANSWER_SKIPPED = 'skipped';

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'product_unit_id', 'step', 'answer', 'note', 'actor_id', 'answered_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'step' => ChecklistStep::class,
            'answered_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ProductUnit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class, 'product_unit_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
