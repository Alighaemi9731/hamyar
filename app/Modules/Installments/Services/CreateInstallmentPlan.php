<?php

declare(strict_types=1);

namespace App\Modules\Installments\Services;

use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Services\LedgerService;
use App\Modules\Installments\Models\InstallmentPlan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Support\Counters\CounterService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Turning an unpaid invoice into a signed schedule.
 *
 * ## What the invoice already did, and what this adds
 *
 * Finalisation posted the unpaid balance as a debit against the customer: they owe the
 * shop that money. This does **not** re-post it — doing so would double the debt.
 *
 * What it does post is the **profit**, because that is money the customer did not owe
 * five minutes ago. «۲۰٪ سود روی ۶ قسط» is a real additional obligation, and without a
 * ledger entry for it the customer's balance would say one number while their contract
 * says another. It is credited to the sales account, which is the Phase 2 placeholder
 * for the real chart of accounts (Phase 7).
 *
 * ## The down payment is not this service's business
 *
 * It is an ordinary payment against the invoice, taken at the till through the ordinary
 * payment box, in whatever mix of cash and card the customer used. By the time a plan is
 * written the invoice's outstanding balance already reflects it — so the financed amount
 * is simply what is still owed, and the plan records the down payment only so the printed
 * contract can quote it.
 *
 * ## One plan per invoice
 *
 * Enforced by a partial unique index as well as here: two schedules against one sale
 * would let a shop chase the same debt twice, with neither plan aware of the other.
 */
final class CreateInstallmentPlan
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly InstallmentScheduler $scheduler,
        private readonly LedgerService $ledger,
        private readonly CounterService $counters,
        private readonly TenantContext $context,
    ) {}

    public function fromInvoice(
        SalesInvoice $invoice,
        int $count,
        int $profitPercent,
        CarbonImmutable $firstDueAt,
        int $intervalMonths = 1,
        ?int $guarantorPartyId = null,
        ?string $notes = null,
        ?int $actorId = null,
    ): InstallmentPlan {
        $this->guard($invoice);

        $tenantId = $this->context->idOrFail();

        // What is actually left to finance. Whatever was paid at the till — the down
        // payment — has already reduced this.
        $financed = $invoice->outstanding();

        $schedule = $this->scheduler->schedule($financed, $count, $profitPercent, $firstDueAt, $intervalMonths);

        /** @var InstallmentPlan $plan */
        $plan = $this->connection->transaction(function () use (
            $invoice, $schedule, $count, $profitPercent, $firstDueAt,
            $intervalMonths, $guarantorPartyId, $notes, $actorId, $tenantId
        ): InstallmentPlan {
            $plan = InstallmentPlan::query()->create([
                'branch_id' => $invoice->branch_id,
                'sales_invoice_id' => $invoice->id,
                'party_id' => $invoice->party_id,
                'guarantor_party_id' => $guarantorPartyId,
                'number' => $this->counters->nextFormatted($tenantId, 'installment_plan', 'INS', $invoice->branch_id),
                // Recorded for the contract, not used in the arithmetic: it is already
                // reflected in what the invoice still owes.
                'down_payment' => $invoice->paid_total,
                'principal' => $schedule['principal'],
                'profit_percent' => $profitPercent,
                'profit_amount' => $schedule['profit_amount'],
                'total_payable' => $schedule['total_payable'],
                'installment_count' => $count,
                'interval_months' => $intervalMonths,
                'first_due_at' => $firstDueAt,
                'notes' => $notes,
                'created_by' => $actorId,
            ]);

            foreach ($schedule['rows'] as $row) {
                $plan->rows()->create([
                    'sequence' => $row['sequence'],
                    'due_at' => $row['due_at'],
                    'amount' => $row['amount'],
                ]);
            }

            $this->postProfit($plan, $invoice, $actorId);

            return $plan;
        });

        return $plan->load('rows');
    }

    private function guard(SalesInvoice $invoice): void
    {
        if (! $invoice->isFinal()) {
            throw new RuntimeException('فقط برای فاکتور نهایی‌شده می‌توان قرارداد اقساطی نوشت.');
        }

        if ($invoice->party_id === null) {
            // Somebody has to sign the contract and be chased for the instalments.
            throw new RuntimeException('فروش اقساطی بدون مشتری ممکن نیست.');
        }

        if ($invoice->outstanding() <= 0) {
            throw new RuntimeException('این فاکتور تسویه شده است و چیزی برای تقسیط ندارد.');
        }

        if ($invoice->installmentPlan()->exists()) {
            throw new RuntimeException('برای این فاکتور قبلاً قرارداد اقساطی ثبت شده است.');
        }
    }

    /**
     * The profit, as a new obligation on the customer.
     *
     * Only the profit. The principal is already on their account from the sale, and
     * posting it again would double what the shop thinks it is owed.
     */
    private function postProfit(InstallmentPlan $plan, SalesInvoice $invoice, ?int $actorId): void
    {
        if ($plan->profit_amount <= 0) {
            return;
        }

        $sales = Account::query()->where('type', Account::TYPE_SALES)->first();

        if (! $sales instanceof Account) {
            throw new RuntimeException('حساب فروش تعریف نشده است؛ سود اقساط جایی برای ثبت ندارد.');
        }

        $this->ledger->post([
            [
                'party_id' => $plan->party_id,
                'branch_id' => $plan->branch_id,
                'debit' => $plan->profit_amount,
                'description' => "سود فروش اقساطی قرارداد {$plan->number}",
            ],
            [
                'account_id' => $sales->id,
                'branch_id' => $plan->branch_id,
                'credit' => $plan->profit_amount,
                'description' => "سود فروش اقساطی قرارداد {$plan->number} (فاکتور {$invoice->number})",
            ],
        ], reference: $plan, actorId: $actorId);
    }
}
