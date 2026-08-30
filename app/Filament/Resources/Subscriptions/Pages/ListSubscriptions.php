<?php

declare(strict_types=1);

namespace App\Filament\Resources\Subscriptions\Pages;

use App\Filament\Resources\Subscriptions\SubscriptionResource;
use Filament\Resources\Pages\ListRecords;

final class ListSubscriptions extends ListRecords
{
    protected static string $resource = SubscriptionResource::class;

    /**
     * No actions, and specifically no Create.
     *
     * `SubscriptionResource::canCreate()` has returned false since Phase 2 — a
     * subscription is the consequence of an invoice and a payment, and one typed in by
     * hand would grant access no money explains. The button was rendered anyway, so the
     * panel offered an action the resource refuses: a dead control, which reads as a bug
     * to whoever presses it and as a working feature to whoever does not.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
