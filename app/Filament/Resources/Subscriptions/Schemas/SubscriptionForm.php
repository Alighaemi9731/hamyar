<?php

declare(strict_types=1);

namespace App\Filament\Resources\Subscriptions\Schemas;

use Filament\Schemas\Schema;

final class SubscriptionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                //
            ]);
    }
}
