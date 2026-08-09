<?php

declare(strict_types=1);

namespace App\Filament\Resources\Coupons\Pages;

use App\Filament\Resources\Coupons\CouponResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateCoupon extends CreateRecord
{
    protected static string $resource = CouponResource::class;
}
