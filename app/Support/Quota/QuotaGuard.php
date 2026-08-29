<?php

declare(strict_types=1);

namespace App\Support\Quota;

/**
 * How much of a thing this shop may still do.
 *
 * The shared-kernel contract every metered module calls, and the reason none of them
 * import Platform (golden rule 6, ADR 0003). Platform owns plans and limits and binds the
 * real implementation; `App\Support` owns the question.
 *
 * ## The one rule that makes it correct
 *
 * `consume()` runs **inside the transaction that writes the row it counts**. Not before
 * it, not after it, not in the controller around it. That single placement buys three
 * properties nothing else does: a failed create rolls the reservation back with it, a
 * refused consume unwinds the writes that came before it, and two requests racing at the
 * last unit serialise on one Postgres row instead of both reading "one left".
 *
 * Two paths are named exceptions to "the transaction that writes the counted row", both
 * because there is no such row: `reporting.exports` (the count IS the write, so it opens
 * its own transaction after the workbook builds) and `messaging.sms` (the message row is
 * committed by an earlier, deliberately idempotent insert).
 *
 * ## Reads are never metered
 *
 * Nothing here is called from a GET that renders a screen, except to draw a meter. A shop
 * that has spent its credit can still look up a customer, print a receipt, run a report
 * and serve its public pages — it just cannot record new work until the month turns or
 * the plan changes.
 */
interface QuotaGuard
{
    /**
     * What would happen, without doing it. No lock, no write, never throws.
     *
     * For pre-flight UX only — the import preview that says «این فایل ۴۰ کالای جدید دارد
     * و سهمیهٔ شما ۱۲ است», the middleware courtesy check, the shared `usage` prop. A
     * verdict from here is already stale when it is read; `consume()` is the only answer
     * that is true at the moment it matters.
     */
    public function check(string $metric, int $n = 1): QuotaVerdict;

    /**
     * Reserve `$n` units, atomically, or refuse the whole thing.
     *
     * @throws QuotaExceeded when it does not fit — nothing is written
     * @throws OutsideTransaction when there is no open transaction to roll back with
     * @throws UnknownMetric when the key was never registered
     */
    public function consume(string $metric, int $n = 1): QuotaVerdict;

    /**
     * Count it if it fits, and never throw.
     *
     * For work the shopkeeper did not just ask for: a queued SMS, a swept reminder. A job
     * that throws on quota retries and eventually alerts, which turns "you have used your
     * messages" into an incident. The caller reads the verdict and decides what to do —
     * `SendSms` marks the message suppressed with a reason the shop can read in the log.
     */
    public function record(string $metric, int $n = 1): QuotaVerdict;

    /**
     * Every counted metric's current standing, for the shared `usage` prop.
     *
     * @return list<QuotaVerdict>
     */
    public function snapshot(): array;
}
