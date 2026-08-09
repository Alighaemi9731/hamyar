<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A discount code.
 *
 * Central, not tenant-scoped: a coupon is the platform's offer and the same code is
 * redeemable by any shop, so it carries no `tenant_id` and needs no RLS policy.
 *
 * `value` means different things per `type` — a percentage (1–100) or an amount in
 * integer rial. Storing both in one column is a small ugliness that avoids a nullable
 * pair where exactly one is always null.
 *
 * @property int $id
 * @property string $code
 * @property string $type
 * @property int $value
 * @property int|null $max_redemptions
 * @property int $redemptions
 * @property CarbonImmutable|null $expires_at
 */
final class Coupon extends Model
{
    public const TYPE_PERCENT = 'percent';

    public const TYPE_FIXED = 'fixed';

    protected $fillable = ['code', 'type', 'value', 'max_redemptions', 'redemptions', 'expires_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'max_redemptions' => 'integer',
            'redemptions' => 'integer',
            'expires_at' => 'immutable_datetime',
        ];
    }

    public function isRedeemable(?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        if ($this->expires_at instanceof CarbonImmutable && $this->expires_at->lessThanOrEqualTo($now)) {
            return false;
        }

        return $this->max_redemptions === null || $this->redemptions < $this->max_redemptions;
    }

    /**
     * What this coupon takes off `$amount`, in rial.
     *
     * Never more than the amount itself: a ۵۰۰,۰۰۰ تومان coupon against a ۲۹۰,۰۰۰ تومان
     * invoice discounts it to zero, it does not produce a negative total that would read
     * as us owing the shop money.
     */
    public function discountFor(int $amount): int
    {
        $discount = $this->type === self::TYPE_PERCENT
            ? \App\Support\Money::percent($amount, $this->value)
            : $this->value;

        return min($discount, $amount);
    }
}
