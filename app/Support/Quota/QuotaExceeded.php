<?php

declare(strict_types=1);

namespace App\Support\Quota;

use Exception;

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
 *
 * ## Why `Exception` and not `RuntimeException`
 *
 * It extended `RuntimeException` until 0.16.0, and that one word disabled the block on
 * most of the product.
 *
 * A dozen controllers wrap their domain call in `catch (RuntimeException $e)` and turn it
 * into a field-level validation message — the established way this codebase reports «این
 * دستگاه فروخته شده» or «موجودی کافی نیست» next to the input that caused it. Every one of
 * those arms swallowed the quota block on its way past: the operator got the raw English
 * `Quota exceeded for [sales.invoices]: 300 used of 300, 1 requested.` under the line-items
 * field, and `quota_block` — the payload carrying the Persian sentence, the limit, the
 * reset date and the upgrade button — never reached the page at all. At the till, on the
 * single screen this feature exists to interrupt gracefully.
 *
 * Nothing crashed. The refusal was correct, the transaction rolled back, no credit was
 * spent; only the *telling* was wrong, which is why no existing test saw it — they all
 * asserted on the counter, and the counter was right. It took driving `POST /sales/pos`
 * at its ceiling to see it (`Sales/tests/Feature/QuotaEnforcementTest.php`).
 *
 * Extending `Exception` puts it outside every one of those catch arms by construction,
 * including in controllers nobody has written yet. That is the point: the alternative fix
 * — adding `catch (QuotaExceeded) { throw; }` above a dozen existing arms — works today
 * and silently stops working the next time somebody adds a thirteenth.
 */
final class QuotaExceeded extends Exception
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
