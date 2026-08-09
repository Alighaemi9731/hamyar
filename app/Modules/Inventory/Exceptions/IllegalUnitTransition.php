<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use App\Modules\Inventory\Enums\UnitStatus;
use App\Modules\Inventory\Models\ProductUnit;
use DomainException;

/**
 * A status change the unit lifecycle does not allow.
 *
 * Carries the identifying code in the message because the operator's next question is
 * always "which phone?", and an exception that says only "illegal transition" sends
 * someone hunting through logs for an id.
 */
final class IllegalUnitTransition extends DomainException
{
    public static function between(ProductUnit $unit, UnitStatus $from, UnitStatus $to): self
    {
        /** @var int|null $id */
        $id = $unit->getKey();

        $identifier = $unit->imei1 ?? $unit->serial ?? ('#'.($id ?? 0));

        return new self(sprintf(
            'Unit %s cannot go from %s to %s. Allowed: %s.',
            $identifier,
            $from->value,
            $to->value,
            implode(', ', array_map(
                static fn (UnitStatus $status): string => $status->value,
                $from->allowedNext()
            )) ?: 'nothing — this status is terminal'
        ));
    }
}
