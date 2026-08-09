<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One quota on a plan. `value` null = unlimited.
 *
 * @property int $id
 * @property int $plan_id
 * @property string $key
 * @property int|null $value
 */
final class PlanLimit extends Model
{
    public const USERS = 'users';

    public const BRANCHES = 'branches';

    public const INVOICES_PER_MONTH = 'invoices_per_month';

    public const STORAGE_MB = 'storage_mb';

    public const SMS_CREDIT_BONUS = 'sms_credit_bonus';

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return [self::USERS, self::BRANCHES, self::INVOICES_PER_MONTH, self::STORAGE_MB, self::SMS_CREDIT_BONUS];
    }

    /**
     * Persian label for a limit key, for the super-admin panel.
     *
     * Lives beside the keys so adding one forces the label into view rather than leaving
     * a raw `sms_credit_bonus` on a staff screen.
     */
    public static function labelFor(string $key): string
    {
        return match ($key) {
            self::USERS => 'کاربران',
            self::BRANCHES => 'شعبه‌ها',
            self::INVOICES_PER_MONTH => 'فاکتور در ماه',
            self::STORAGE_MB => 'فضای ذخیره‌سازی (مگابایت)',
            self::SMS_CREDIT_BONUS => 'پیامک هدیه',
            default => $key,
        };
    }

    protected $fillable = ['plan_id', 'key', 'value'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['value' => 'integer'];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
