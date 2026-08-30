<?php

declare(strict_types=1);

namespace App\Filament\Resources\Plans\Pages;

use App\Filament\Resources\Plans\Concerns\EditsPlanLimits;
use App\Filament\Resources\Plans\PlanResource;
use Filament\Resources\Pages\CreateRecord;

final class CreatePlan extends CreateRecord
{
    use EditsPlanLimits;

    protected static string $resource = PlanResource::class;
}
