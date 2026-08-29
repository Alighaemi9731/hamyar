<?php

declare(strict_types=1);

namespace App\Support\Quota;

use RuntimeException;

/**
 * `consume()` was called with no open transaction.
 *
 * The reservation and the row it counts must commit or roll back together. Outside a
 * transaction the increment survives a failed create — the shop is charged a unit of
 * quota for an invoice that does not exist — and a refused consume cannot unwind the
 * writes that came before it.
 *
 * Same shape as {@see \App\Support\Counters\CounterService}'s guard, and the same reason:
 * a contract that only works inside a transaction should say so at the moment it is
 * broken, not produce a wrong number later.
 */
final class OutsideTransaction extends RuntimeException
{
    public function __construct(string $metric)
    {
        parent::__construct(
            "QuotaGuard::consume('{$metric}') must run inside the transaction that writes the row it counts — "
            .'outside one the reservation cannot roll back with it.'
        );
    }
}
