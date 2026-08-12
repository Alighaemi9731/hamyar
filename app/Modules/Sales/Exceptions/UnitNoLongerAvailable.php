<?php

declare(strict_types=1);

namespace App\Modules\Sales\Exceptions;

use RuntimeException;

/**
 * Someone else sold that handset first.
 *
 * Carries the device so the screen can name it. "دستگاه دیگر موجود نیست" sends a
 * salesperson hunting through a basket of six phones; naming the IMEI tells them which
 * line to remove while the customer is still standing there.
 */
final class UnitNoLongerAvailable extends RuntimeException
{
    public function __construct(
        public readonly int $unitId,
        public readonly ?string $imei,
        public readonly string $productName,
        public readonly string $currentStatus,
    ) {
        $identifier = $imei === null ? $productName : "{$productName} ({$imei})";

        parent::__construct("این دستگاه دیگر قابل فروش نیست: {$identifier}.");
    }
}
