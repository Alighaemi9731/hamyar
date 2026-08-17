<?php

declare(strict_types=1);

namespace App\Modules\Hamta\Enums;

/**
 * Where a device stands with the national registry, **as far as this shop recorded**.
 *
 * Every value here is an assertion by a person, never a fact checked against همتا. There is
 * no public API (see the module docblock), so `Done` means "somebody typed an activation id
 * they said they received", not "the transfer completed". The UI has to keep saying so —
 * a status that looks verified is a promise this product cannot keep.
 */
enum HamtaStatus: string
{
    /** A new device sold, or anything with no transfer obligation. */
    case NotRequired = 'not_required';

    /** A used device changed hands and the transfer has not been recorded as done. */
    case Pending = 'pending';

    /** Somebody recorded a completed transfer. Recorded, not verified. */
    case Done = 'done';

    public function labelFa(): string
    {
        return match ($this) {
            self::NotRequired => 'نیازی نیست',
            self::Pending => 'در انتظار انتقال',
            self::Done => 'ثبت‌شده',
        };
    }

    /**
     * Whether this device should be showing a warning wherever it appears.
     */
    public function needsAttention(): bool
    {
        return $this === self::Pending;
    }
}
