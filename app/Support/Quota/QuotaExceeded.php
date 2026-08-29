<?php

declare(strict_types=1);

namespace App\Support\Quota;

use RuntimeException;

/**
 * The shop asked to do more than its plan allows.
 *
 * Thrown from inside the caller's transaction, so the domain write it was guarding
 * unwinds with it — nothing is half-created. It is rendered centrally as an error-bag
 * entry plus the block payload, never as a 4xx page: the shopkeeper is mid-task with a
 * customer at the counter, and the answer they need is one sentence and an upgrade
 * button on the screen they are already on.
 *
 * It carries the verdict rather than a message, so the renderer owns the copy and this
 * stays a domain signal.
 */
final class QuotaExceeded extends RuntimeException
{
    public function __construct(public readonly QuotaVerdict $verdict)
    {
        parent::__construct(sprintf(
            'Quota exceeded for [%s]: %d used of %s, %d requested.',
            $verdict->metric,
            $verdict->used,
            $verdict->limit === null ? 'unlimited' : (string) $verdict->limit,
            $verdict->requested,
        ));
    }
}
