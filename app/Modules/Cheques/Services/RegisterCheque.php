<?php

declare(strict_types=1);

namespace App\Modules\Cheques\Services;

use App\Modules\Cheques\Enums\ChequeDirection;
use App\Modules\Cheques\Models\Cheque;
use App\Modules\CRM\Models\Account;
use App\Support\Quota\QuotaGuard;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * The way a cheque enters the system — the door that did not exist until `0.20.0`.
 *
 * ## What was wrong before
 *
 * Every transition in {@see ChequeTransitions} worked, every row of the posting matrix was
 * pinned by `ChequePostingMatrixTest`, the exposure check counted uncleared cheques, and
 * `VoidInvoice` refused an invoice with a live one. All of it correct, and **none of it
 * reachable**: across 104 write routes nothing created a `Cheque`. The row was written in
 * nine test files and zero production files, while `cheques.cheques` sat on the plan ladder
 * advertising «۵۰ ثبت چک در ماه» for something a shop could not do once.
 *
 * ## Why registering is its own service rather than a controller method
 *
 * Creating the row and posting its first ledger entry have to be one atomic act. A cheque
 * that exists without its opening posting is worse than no cheque: `ChequeExposure` counts
 * it toward a customer's credit while the ledger does not know it, so the two answers a shop
 * gets about the same customer disagree. So the create, the quota spend and the transition
 * share one transaction here, where a controller cannot accidentally split them.
 *
 * ## Two directions, two different postings
 *
 * A **received** cheque is paper a customer handed over: it debits `cheques_receivable` and
 * credits the party (R1). An **issued** cheque is our own, drawn on our bank: it debits the
 * party and credits `cheques_payable` (I1), and needs the bank account it is drawn on.
 * Both land in `in_hand`, and everything afterwards is the existing state machine.
 */
final class RegisterCheque
{
    public function __construct(
        private readonly ChequeTransitions $transitions,
        private readonly QuotaGuard $quota,
        private readonly ConnectionInterface $connection,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  already validated by `ChequeRequest::columns()`
     */
    public function register(
        ChequeDirection $direction,
        array $attributes,
        ?Account $drawnOn = null,
        ?int $actorId = null,
    ): Cheque {
        if ($direction === ChequeDirection::Issued && ! $drawnOn instanceof Account) {
            // Guarded here as well as in the FormRequest: this service is a module's public
            // interface, and a caller that is not the HTTP layer gets the same refusal.
            throw new RuntimeException('برای چک صادره باید حساب بانکی مبدأ را انتخاب کنید.');
        }

        /** @var Cheque $cheque */
        $cheque = $this->connection->transaction(function () use ($direction, $attributes, $drawnOn, $actorId): Cheque {
            /*
            | The credit is spent inside the transaction that writes the row it counts
            | (golden rule 7). A registration that fails its posting must not leave a credit
            | spent, and a spent credit must always have a cheque behind it.
            */
            $this->quota->consume('cheques.cheques');

            /** @var Cheque $cheque */
            $cheque = Cheque::query()->create([
                ...$attributes,
                'direction' => $direction->value,
                'actor_id' => $actorId,
            ]);

            $at = $cheque->received_at instanceof CarbonImmutable
                ? $cheque->received_at
                : CarbonImmutable::now();

            return $direction === ChequeDirection::Received
                ? $this->transitions->receive($cheque, $at, $actorId)
                : $this->transitions->issue($cheque, $drawnOn, $at, $actorId);
        });

        return $cheque;
    }
}
