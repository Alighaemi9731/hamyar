<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

/**
 * New, used, or refurbished.
 *
 * Separate from `grade`: condition is what the device *is*, grade is how good an example
 * of it this one is. A used phone is always `Used` whether it is grade A or C, and a new
 * one has no grade at all.
 */
enum UnitCondition: string
{
    case New = 'new';

    case Used = 'used';

    case Refurbished = 'refurb';

    public function labelFa(): string
    {
        return match ($this) {
            self::New => 'نو',
            self::Used => 'دست‌دوم',
            self::Refurbished => 'بازسازی‌شده',
        };
    }

    /**
     * Whether a cosmetic grade is meaningful. A new sealed device has no grade.
     */
    public function usesGrade(): bool
    {
        return $this !== self::New;
    }
}
