<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use App\Modules\Inventory\Models\Warehouse;
use DomainException;

/**
 * A withdrawal that would take a warehouse below zero.
 *
 * Carries the numbers because the cashier's next question is "how many do we have?", and
 * an error that only says "not enough" sends them to another screen to find out.
 */
final class InsufficientStock extends DomainException
{
    public function __construct(
        string $message,
        public readonly int $available,
        public readonly int $requested,
    ) {
        parent::__construct($message);
    }

    public static function for(int $variantId, Warehouse $warehouse, int $available, int $requested): self
    {
        return new self(
            sprintf(
                'Variant %d has %d in %s, but %d were requested. Enable negative stock on that warehouse to allow it.',
                $variantId,
                $available,
                $warehouse->name,
                $requested
            ),
            $available,
            $requested
        );
    }
}
