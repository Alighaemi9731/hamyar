<?php

declare(strict_types=1);

namespace App\Modules\Cheques\Services;

use App\Modules\Cheques\Enums\ChequeDirection;
use App\Modules\Cheques\Enums\ChequeStatus;
use App\Modules\Cheques\Events\ChequeBounced;
use App\Modules\Cheques\Models\Cheque;
use App\Modules\Cheques\Models\ChequeEvent;
use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Services\LedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The cheque lifecycle, posting exactly what `docs/specs/cheques.md` says it posts.
 *
 * **That document is the specification and this is the implementation.** Every method
 * below names the matrix row it implements (R1, R5, I3 …), and
 * `ChequePostingMatrixTest` pins every row of the table to a test. A change to one
 * without the other is visibly incomplete, which is the point of numbering them.
 *
 * ## The central decision, restated because it is easy to undo by accident
 *
 * **A received cheque settles the customer's debt when it is taken**, not when it clears.
 * The shop swaps an open claim on a person for a dated claim on paper and is no richer at
 * that instant. Everything else in this file follows from that; the arguments are in the
 * spec, and the objection it raises — a customer's balance reading zero while their paper
 * might yet bounce — is answered by {@see ChequeExposure}, not by weakening this rule.
 *
 * ## Every transition locks and re-reads
 *
 * Two operators in two tabs both hold a stale model, both pass a guard read from it, and
 * both post. That is not hypothetical: it produced two invoices and a doubled cash posting
 * in Phase 6 before delivery was locked. The status check under the lock **is** the
 * idempotency key — a repeat is refused with a message naming the cheque, never silently
 * ignored and never posted twice.
 *
 * ## One subject per ledger row
 *
 * A row naming both a party and an account counts toward both balances, so the debit
 * cancels the credit inside the same batch and the settlement silently does not happen.
 * The customer's name goes in the description. Pinned by a test.
 */
final class ChequeTransitions
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly LedgerService $ledger,
        private readonly ChequeAccounts $accounts,
    ) {}

    /**
     * R1 — a cheque is taken from a customer.
     *
     * DEBIT cheques_receivable / CREDIT party, at face value.
     */
    public function receive(Cheque $cheque, ?CarbonImmutable $at = null, ?int $actorId = null): Cheque
    {
        return $this->apply(
            $cheque,
            to: ChequeStatus::InHand,
            at: $at,
            actorId: $actorId,
            note: "دریافت چک {$cheque->serial} بانک {$cheque->bank_name}",
            lines: fn (Cheque $c): array => [
                ['account_id' => $this->accounts->receivable()->id, 'debit' => $c->amount],
                ['party_id' => $c->party_id, 'credit' => $c->amount],
            ],
            from: null,
            stamps: ['received_at' => $at ?? CarbonImmutable::now()],
        );
    }

    /**
     * R2 — lodged with a bank for collection.
     *
     * DEBIT cheques_in_collection / CREDIT cheques_receivable. Depositing posts; it is not
     * a status change. The tempting error is debiting the bank itself, which inflates the
     * balance a shop uses to decide whether a transfer will clear by paper the bank has not
     * honoured — and reconciliation can then never tie out.
     */
    public function deposit(Cheque $cheque, Account $bank, ?CarbonImmutable $at = null, ?int $actorId = null): Cheque
    {
        if ($bank->type !== Account::TYPE_BANK) {
            throw new RuntimeException('چک فقط به حساب بانکی سپرده می‌شود.');
        }

        $from = $cheque->status;

        // R7 — re-presentation after a bounce posts DIFFERENT lines. The drawer account
        // holds nothing for this cheque any more: the bounce credited collection and
        // debited the party, so the value sits on the customer and the customer is what
        // must be credited. Copying R2's lines here would drive `cheques_receivable`
        // negative by the face value, permanently.
        $isRepresentation = $from === ChequeStatus::Bounced;

        return $this->apply(
            $cheque,
            to: ChequeStatus::Deposited,
            at: $at,
            actorId: $actorId,
            note: $isRepresentation
                ? "ارائه مجدد چک {$cheque->serial} (نوبت ".($cheque->presentation_attempt + 1).')'
                : "سپردن چک {$cheque->serial} به {$bank->name}",
            lines: fn (Cheque $c): array => $isRepresentation
                ? [
                    ['account_id' => $this->accounts->inCollection()->id, 'debit' => $c->outstanding()],
                    ['party_id' => $c->party_id, 'credit' => $c->outstanding()],
                ]
                : [
                    ['account_id' => $this->accounts->inCollection()->id, 'debit' => $c->amount],
                    ['account_id' => $this->accounts->receivable()->id, 'credit' => $c->amount],
                ],
            allowedFrom: [ChequeStatus::InHand, ChequeStatus::Bounced],
            stamps: [
                'account_id' => $bank->id,
                'deposited_at' => $at ?? CarbonImmutable::now(),
                'presentation_attempt' => $isRepresentation ? $cheque->presentation_attempt + 1 : 1,
            ],
        );
    }

    /**
     * R3 / R4 — the bank paid.
     *
     * From `deposited`: DEBIT the deposit bank / CREDIT cheques_in_collection.
     * From `in_hand`: cashed over the counter at the drawee branch — DEBIT cash or bank /
     * CREDIT cheques_receivable, with nothing routed through collection, because nothing
     * was ever in transit.
     *
     * A collection fee is folded into this batch rather than posted separately: the bank
     * nets it into the same credit line on the statement, and reconciliation ties against
     * what the statement shows.
     */
    public function clear(
        Cheque $cheque,
        ?Account $into = null,
        int $fee = 0,
        ?CarbonImmutable $at = null,
        ?int $actorId = null,
    ): Cheque {
        $from = $cheque->status;
        $wasDeposited = $from === ChequeStatus::Deposited;

        $destination = $into ?? $cheque->account;

        if (! $destination instanceof Account || ! $destination->holdsMoney()) {
            throw new RuntimeException('برای وصول چک باید حساب مقصد مشخص باشد.');
        }

        if ($wasDeposited && $into !== null && $into->id !== $cheque->account_id) {
            // The destination was fixed at deposit. Re-choosing it here would make the
            // ledger say money arrived somewhere it did not.
            throw new RuntimeException('چک به حسابی غیر از حساب سپرده‌شده وصول نمی‌شود.');
        }

        $fee = max(0, $fee);

        return $this->apply(
            $cheque,
            to: ChequeStatus::Cleared,
            at: $at,
            actorId: $actorId,
            note: "وصول چک {$cheque->serial}",
            lines: function (Cheque $c) use ($wasDeposited, $destination, $fee): array {
                $source = $wasDeposited
                    ? $this->accounts->inCollection()->id
                    : $this->accounts->receivable()->id;

                $lines = [
                    ['account_id' => $destination->id, 'debit' => $c->outstanding() - $fee],
                    ['account_id' => $source, 'credit' => $c->outstanding()],
                ];

                if ($fee > 0) {
                    $lines[] = ['account_id' => $this->accounts->bankCharges()->id, 'debit' => $fee];
                }

                return $lines;
            },
            allowedFrom: [ChequeStatus::InHand, ChequeStatus::Deposited],
            stamps: ['cleared_at' => $at ?? CarbonImmutable::now(), 'account_id' => $destination->id],
        );
    }

    /**
     * R5 / R6 — the bank refused, in full or in part.
     *
     * One batch: DEBIT party (the shortfall) + DEBIT bank (anything actually recovered) /
     * CREDIT cheques_in_collection at face. Splitting the restoration from the collection
     * credit would leave a window in which the books claim the shop both holds a good
     * asset and is owed nothing — and a crash leaves it that way.
     *
     * A bounce is a NEW dated event, never a reversal of the receipt. Reversing would
     * credit `cheques_receivable`, but the cheque is in collection, not in hand.
     *
     * The bank's returned-item charge is its own batch: it appears as its own debit on its
     * own day on the statement, and separating it means a disputed fee can be reversed
     * without touching the restoration of the customer's debt.
     */
    public function bounce(
        Cheque $cheque,
        string $reason,
        int $recovered = 0,
        int $fee = 0,
        ?CarbonImmutable $at = null,
        ?int $actorId = null,
    ): Cheque {
        $recovered = max(0, min($recovered, $cheque->amount));
        $fee = max(0, $fee);
        $at ??= CarbonImmutable::now();

        $bounced = $this->apply(
            $cheque,
            to: ChequeStatus::Bounced,
            at: $at,
            actorId: $actorId,
            note: "برگشت چک {$cheque->serial} — {$reason}",
            lines: function (Cheque $c) use ($recovered): array {
                $shortfall = $c->amount - $recovered;

                $lines = [['account_id' => $this->accounts->inCollection()->id, 'credit' => $c->amount]];

                if ($shortfall > 0) {
                    $lines[] = ['party_id' => $c->party_id, 'debit' => $shortfall];
                }

                if ($recovered > 0) {
                    // The bank paid what was in the account and certified the rest —
                    // گواهینامه عدم پرداخت. One event, one batch.
                    $lines[] = ['account_id' => $c->account_id, 'debit' => $recovered];
                }

                return $lines;
            },
            // ILLEGAL from in_hand: an unpresented cheque cannot bounce. A teller
            // verbally refusing to pay is a note, not a state change.
            allowedFrom: [ChequeStatus::Deposited],
            stamps: [
                'bounced_at' => $at,
                'bounce_reason' => $reason,
                'recovered_amount' => $recovered,
            ],
        );

        if ($fee > 0) {
            $this->ledger->post([
                ['account_id' => $this->accounts->bankCharges()->id, 'debit' => $fee, 'description' => "کارمزد چک برگشتی {$cheque->serial}"],
                ['account_id' => $cheque->account_id, 'credit' => $fee, 'description' => "کارمزد چک برگشتی {$cheque->serial}"],
            ], $bounced, $at, $actorId);
        }

        DB::afterCommit(fn () => ChequeBounced::dispatch($bounced));

        return $bounced;
    }

    /**
     * R8 — خرج کردن چک: endorsed to a supplier to settle the shop's own debt.
     *
     * DEBIT the endorsee / CREDIT cheques_receivable. Because a positive party balance
     * means the party owes the shop, debiting the supplier moves what the shop owes them
     * toward zero. If the cheque is larger than the payable, their balance goes positive —
     * they owe the shop change — which is the truth and must not be clamped.
     */
    public function endorse(Cheque $cheque, int $endorseeId, ?CarbonImmutable $at = null, ?int $actorId = null): Cheque
    {
        if ($endorseeId === $cheque->party_id) {
            // Endorsing back to the drawer is `return`, a different transition with
            // different lines.
            throw new RuntimeException('واگذاری چک به صادرکننده همان، «استرداد» است نه «خرج کردن».');
        }

        return $this->apply(
            $cheque,
            to: ChequeStatus::SpentToThirdParty,
            at: $at,
            actorId: $actorId,
            note: "خرج کردن چک {$cheque->serial}",
            lines: fn (Cheque $c): array => [
                ['party_id' => $endorseeId, 'debit' => $c->amount],
                ['account_id' => $this->accounts->receivable()->id, 'credit' => $c->amount],
            ],
            // ILLEGAL from deposited (the bank holds the paper) and from bounced —
            // endorsing paper known to be dishonoured is passing a bad cheque onward, and
            // this software will not be the tool that does it.
            allowedFrom: [ChequeStatus::InHand],
            stamps: ['endorsed_to_party_id' => $endorseeId],
        );
    }

    /**
     * R10 — the endorsee's bank dishonoured it and told us.
     *
     * DEBIT cheques_returned / CREDIT the endorsee: the shop's debt to them revives on
     * notification, not on the paper physically coming back.
     */
    public function returnedByEndorsee(Cheque $cheque, ?CarbonImmutable $at = null, ?int $actorId = null): Cheque
    {
        return $this->apply(
            $cheque,
            to: ChequeStatus::ReturnedByEndorsee,
            at: $at,
            actorId: $actorId,
            note: "برگشت چک {$cheque->serial} از شخص ثالث",
            lines: fn (Cheque $c): array => [
                ['account_id' => $this->accounts->returned()->id, 'debit' => $c->amount],
                ['party_id' => (int) $c->endorsed_to_party_id, 'credit' => $c->amount],
            ],
            allowedFrom: [ChequeStatus::SpentToThirdParty],
        );
    }

    /**
     * R11 — and now chase the person who wrote it.
     *
     * DEBIT the drawer / CREDIT cheques_returned. Refused unless R10 has been posted, or
     * `cheques_returned` goes negative.
     */
    public function chaseDrawer(Cheque $cheque, ?CarbonImmutable $at = null, ?int $actorId = null): Cheque
    {
        return $this->apply(
            $cheque,
            to: ChequeStatus::Bounced,
            at: $at,
            actorId: $actorId,
            note: "پیگیری چک {$cheque->serial} از صادرکننده",
            lines: fn (Cheque $c): array => [
                ['party_id' => $c->party_id, 'debit' => $c->amount],
                ['account_id' => $this->accounts->returned()->id, 'credit' => $c->amount],
            ],
            allowedFrom: [ChequeStatus::ReturnedByEndorsee],
        );
    }

    /**
     * R12 — handed back to the customer, deal cancelled.
     *
     * DEBIT the drawer / CREDIT cheques_receivable: they owe again, because the thing that
     * settled their debt is back in their pocket.
     */
    public function returnToDrawer(Cheque $cheque, ?CarbonImmutable $at = null, ?int $actorId = null): Cheque
    {
        return $this->apply(
            $cheque,
            to: ChequeStatus::Returned,
            at: $at,
            actorId: $actorId,
            note: "استرداد چک {$cheque->serial} به صادرکننده",
            lines: fn (Cheque $c): array => [
                ['party_id' => $c->party_id, 'debit' => $c->amount],
                ['account_id' => $this->accounts->receivable()->id, 'credit' => $c->amount],
            ],
            // Only while the shop holds the paper. A cheque at the bank or already
            // endorsed cannot be handed back.
            allowedFrom: [ChequeStatus::InHand],
        );
    }

    /**
     * R13 — given up on.
     *
     * DEBIT bad debt / CREDIT the drawer. Their balance goes to zero because the shop has
     * decided to stop counting it as an asset — not because they paid.
     */
    public function writeOff(Cheque $cheque, ?CarbonImmutable $at = null, ?int $actorId = null): Cheque
    {
        return $this->apply(
            $cheque,
            to: ChequeStatus::WrittenOff,
            at: $at,
            actorId: $actorId,
            note: "سوخت چک {$cheque->serial}",
            lines: fn (Cheque $c): array => [
                ['account_id' => $this->accounts->badDebt()->id, 'debit' => $c->outstanding()],
                ['party_id' => $c->party_id, 'credit' => $c->outstanding()],
            ],
            allowedFrom: [ChequeStatus::Bounced, ChequeStatus::ReturnedByEndorsee],
        );
    }

    /* ------------------------------------------------- issued cheques -- */

    /**
     * I1 — the shop writes a cheque.
     *
     * DEBIT the payee / CREDIT cheques_payable. The shop's debt to them falls; a liability
     * for the paper takes its place.
     */
    public function issue(Cheque $cheque, Account $drawnOn, ?CarbonImmutable $at = null, ?int $actorId = null): Cheque
    {
        if ($drawnOn->type !== Account::TYPE_BANK) {
            throw new RuntimeException('چک فقط روی حساب بانکی صادر می‌شود.');
        }

        return $this->apply(
            $cheque,
            to: ChequeStatus::InHand,
            at: $at,
            actorId: $actorId,
            note: "صدور چک {$cheque->serial}",
            lines: fn (Cheque $c): array => [
                ['party_id' => $c->party_id, 'debit' => $c->amount],
                ['account_id' => $this->accounts->payable()->id, 'credit' => $c->amount],
            ],
            from: null,
            stamps: ['account_id' => $drawnOn->id],
        );
    }

    /**
     * I2 — the payee lodged it. Posts NOTHING, deliberately.
     *
     * The asymmetry with R2 is considered, not an oversight. On the received side an
     * in-collection account buys a real invariant — its balance equals the paper in the
     * drawer, which is how a shop discovers a stolen cheque. Here there is no equivalent
     * to buy: the shop's obligation is the same size either way, the money is still in the
     * bank account and still mis-spendable, and no report distinguishes the two.
     */
    public function markPresented(Cheque $cheque, ?CarbonImmutable $at = null, ?int $actorId = null): Cheque
    {
        return $this->apply(
            $cheque,
            to: ChequeStatus::Presented,
            at: $at,
            actorId: $actorId,
            note: "ارائه چک {$cheque->serial} توسط ذی‌نفع",
            lines: fn (Cheque $c): array => [],
            allowedFrom: [ChequeStatus::InHand],
        );
    }

    /**
     * I3 — our cheque cleared.
     *
     * DEBIT cheques_payable / CREDIT the drawn-on bank. The money finally leaves.
     */
    public function clearIssued(Cheque $cheque, int $fee = 0, ?CarbonImmutable $at = null, ?int $actorId = null): Cheque
    {
        $fee = max(0, $fee);

        return $this->apply(
            $cheque,
            to: ChequeStatus::Cleared,
            at: $at,
            actorId: $actorId,
            note: "پاس شدن چک {$cheque->serial}",
            lines: function (Cheque $c) use ($fee): array {
                $lines = [
                    ['account_id' => $this->accounts->payable()->id, 'debit' => $c->amount],
                    ['account_id' => (int) $c->account_id, 'credit' => $c->amount + $fee],
                ];

                if ($fee > 0) {
                    $lines[] = ['account_id' => $this->accounts->bankCharges()->id, 'debit' => $fee];
                }

                return $lines;
            },
            allowedFrom: [ChequeStatus::InHand, ChequeStatus::Presented],
            stamps: ['cleared_at' => $at ?? CarbonImmutable::now()],
        );
    }

    /**
     * I4 — our own cheque bounced. The most serious thing in this module.
     *
     * DEBIT cheques_payable / CREDIT the payee: the liability for the paper is gone and
     * the plain debt is back. The bank takes its charge regardless, in the same batch,
     * because it lands on the shop's statement as part of the same event.
     */
    public function bounceIssued(
        Cheque $cheque,
        string $reason,
        int $fee = 0,
        ?CarbonImmutable $at = null,
        ?int $actorId = null,
    ): Cheque {
        $fee = max(0, $fee);
        $at ??= CarbonImmutable::now();

        $bounced = $this->apply(
            $cheque,
            to: ChequeStatus::Bounced,
            at: $at,
            actorId: $actorId,
            note: "برگشت چک صادره {$cheque->serial} — {$reason}",
            lines: function (Cheque $c) use ($fee): array {
                $lines = [
                    ['account_id' => $this->accounts->payable()->id, 'debit' => $c->amount],
                    ['party_id' => $c->party_id, 'credit' => $c->amount],
                ];

                if ($fee > 0) {
                    $lines[] = ['account_id' => $this->accounts->bankCharges()->id, 'debit' => $fee];
                    $lines[] = ['account_id' => (int) $c->account_id, 'credit' => $fee];
                }

                return $lines;
            },
            allowedFrom: [ChequeStatus::InHand, ChequeStatus::Presented],
            stamps: ['bounced_at' => $at, 'bounce_reason' => $reason],
        );

        DB::afterCommit(fn () => ChequeBounced::dispatch($bounced));

        return $bounced;
    }

    /**
     * I7 — cancelled before it was ever presented.
     *
     * DEBIT cheques_payable / CREDIT the payee. The paper is void; the underlying debt,
     * if any, is back to being an ordinary one.
     */
    public function cancel(Cheque $cheque, ?CarbonImmutable $at = null, ?int $actorId = null): Cheque
    {
        return $this->apply(
            $cheque,
            to: ChequeStatus::Cancelled,
            at: $at,
            actorId: $actorId,
            note: "ابطال چک {$cheque->serial}",
            lines: fn (Cheque $c): array => $c->direction === ChequeDirection::Issued
                ? [
                    ['account_id' => $this->accounts->payable()->id, 'debit' => $c->amount],
                    ['party_id' => $c->party_id, 'credit' => $c->amount],
                ]
                : [
                    ['party_id' => $c->party_id, 'debit' => $c->amount],
                    ['account_id' => $this->accounts->receivable()->id, 'credit' => $c->amount],
                ],
            allowedFrom: [ChequeStatus::InHand, ChequeStatus::Presented],
        );
    }

    /* ------------------------------------------------------ the engine -- */

    /**
     * Lock, re-read, guard, post, stamp, record.
     *
     * @param  callable(Cheque): list<array{party_id?: int|null, account_id?: int|null, debit?: int, credit?: int, description?: string|null, branch_id?: int|null}>  $lines
     * @param  list<ChequeStatus>|null  $allowedFrom  null for a creation transition
     * @param  array<string, mixed>  $stamps
     */
    private function apply(
        Cheque $cheque,
        ChequeStatus $to,
        ?CarbonImmutable $at,
        ?int $actorId,
        string $note,
        callable $lines,
        ?array $allowedFrom = null,
        ?ChequeStatus $from = null,
        array $stamps = [],
    ): Cheque {
        $at ??= CarbonImmutable::now();

        /** @var Cheque $result */
        $result = $this->connection->transaction(function () use ($cheque, $to, $at, $actorId, $note, $lines, $allowedFrom, $stamps): Cheque {
            /*
            | Lock FIRST, then re-read and re-check.
            |
            | Two operators pressing the same button in two tabs both hold a stale model
            | and both pass a guard read from it. The status check under this lock is what
            | makes a repeat a refusal rather than a second posting.
            */
            $locked = Cheque::query()->lockForUpdate()->find($cheque->getKey());

            if (! $locked instanceof Cheque) {
                throw new RuntimeException('این چک دیگر در سیستم نیست.');
            }

            $cheque->setRawAttributes($locked->getRawOriginal(), true);

            $current = $cheque->status;

            if ($allowedFrom !== null && ! in_array($current, $allowedFrom, true)) {
                throw new RuntimeException(
                    "چک {$cheque->serial} در وضعیت «{$current->labelFa()}» است و این عملیات روی آن ممکن نیست."
                );
            }

            $batchId = null;
            $posted = $lines($cheque);

            if ($posted !== []) {
                $entries = $this->ledger->post(
                    array_map(fn (array $line): array => [...$line, 'description' => $line['description'] ?? $note], $posted),
                    $cheque,
                    $at,
                    $actorId,
                );

                $batchId = $entries[0]->batch_id ?? null;
            }

            $cheque->forceFill([...$stamps, 'status' => $to])->save();

            ChequeEvent::query()->create([
                'cheque_id' => $cheque->getKey(),
                'from_status' => $current,
                'to_status' => $to,
                'batch_id' => $batchId,
                'amount' => $cheque->amount,
                'note' => $note,
                'occurred_at' => $at,
                'actor_id' => $actorId,
            ]);

            return $cheque;
        });

        return $result;
    }
}
