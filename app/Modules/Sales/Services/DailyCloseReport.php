<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\Sales\Enums\InvoiceStatus;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Models\InvoicePayment;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use Carbon\CarbonImmutable;

/**
 * گزارش Z — what the till did today, in the order somebody closing it needs to read it.
 *
 * ## The question this answers is "how much cash should be in the drawer"
 *
 * Not "what did we sell". A Z-report exists because at the end of the day a person
 * counts notes and has to know what the number should be, and every figure here is
 * chosen to make that comparison possible or to explain a difference in it.
 *
 * So the payment breakdown is **by method and by account**, not one total. Cash, the
 * card terminal and a card-to-card transfer to the owner's personal account are three
 * different piles of money in three different places, and a single "collected" figure
 * cannot be reconciled against any of them.
 *
 * ## Cash out is netted, and shown separately
 *
 * A refund to a walk-in comes out of the drawer. It has to be subtracted from what the
 * drawer should hold, and it has to be visible — a shop that finds 4,000,000 missing
 * needs to see the refund that explains it, not a smaller expected total with no reason.
 *
 * ## Voided invoices are counted, not hidden
 *
 * They contribute nothing to the money, but "we voided six invoices today" is exactly
 * the sort of thing an owner wants to see on a Z-report, and hiding it is how a till
 * gets quietly abused.
 *
 * ## Cheques and trade-ins are not money in the drawer
 *
 * Both settle invoices; neither puts a note anywhere. They are reported so the day's
 * sales add up, and kept out of the expected-cash figure so the drawer still balances.
 */
final class DailyCloseReport
{
    public function __construct(private readonly ProfitEngine $profit) {}

    /**
     * @return array{
     *     invoice_count: int,
     *     void_count: int,
     *     return_count: int,
     *     gross: int,
     *     discount: int,
     *     vat: int,
     *     shipping: int,
     *     rounding: int,
     *     net: int,
     *     refunded: int,
     *     credit_extended: int,
     *     expected_cash: int,
     *     payments: list<array{method: string, label: string, settles_now: bool, count: int, amount: int}>,
     *     accounts: list<array{account_id: int|null, name: string, amount: int}>,
     *     profit: array{revenue: int, cost: int, profit: int, margin_percent: int, invoice_count: int, returned_revenue: int, returned_cost: int}|null
     * }
     */
    public function forDay(CarbonImmutable $from, CarbonImmutable $to, ?int $branchId = null, bool $withProfit = false): array
    {
        $issued = $this->issuedInvoices($from, $to, $branchId);
        $payments = $this->paymentBreakdown($from, $to, $branchId);
        $refunded = $this->refunded($from, $to, $branchId);

        $cash = $this->amountFor($payments, PaymentMethod::Cash);

        return [
            'invoice_count' => $issued['invoice_count'],
            'void_count' => $this->voidCount($from, $to, $branchId),
            'return_count' => $this->returnCount($from, $to, $branchId),

            'gross' => $issued['gross'],
            'discount' => $issued['discount'],
            'vat' => $issued['vat'],
            'shipping' => $issued['shipping'],
            'rounding' => $issued['rounding'],
            'net' => $issued['net'],

            'refunded' => $refunded,

            // What the shop let walk out unpaid today: the sales total less everything
            // that settled it. The figure an owner asks about on a Thursday.
            'credit_extended' => max(0, $issued['net'] - $this->settledTotal($payments)),

            // The number the person counting the drawer is comparing against. Cash in,
            // less cash refunded out — and nothing else, because nothing else is in it.
            'expected_cash' => $cash - $refunded,

            'payments' => $this->paymentRows($payments),
            'accounts' => $this->accountRows($from, $to, $branchId),

            /*
            | Wrapped, not widened. A daily close is deliberately **one** branch — you
            | close one till — so this service keeps its `?int`, and only the engine
            | underneath it took a list when reporting needed to express "the branches
            | this manager is allowed".
            */
            'profit' => $withProfit
                ? $this->profit->forPeriod($from, $to, $branchId === null ? null : [$branchId])
                : null,
        ];
    }

    /**
     * The day's issued invoices, summed.
     *
     * @return array{invoice_count: int, gross: int, discount: int, vat: int, shipping: int, rounding: int, net: int}
     */
    private function issuedInvoices(CarbonImmutable $from, CarbonImmutable $to, ?int $branchId): array
    {
        $row = SalesInvoice::query()
            ->where('type', SalesInvoice::TYPE_INVOICE)
            ->where('status', InvoiceStatus::Final->value)
            ->whereBetween('issued_at', [$from, $to])
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->selectRaw('
                count(*) as invoice_count,
                coalesce(sum(subtotal), 0) as gross,
                coalesce(sum(discount_amount), 0) as discount,
                coalesce(sum(vat_amount), 0) as vat,
                coalesce(sum(shipping_amount), 0) as shipping,
                coalesce(sum(rounding_adjustment), 0) as rounding,
                coalesce(sum(total), 0) as net
            ')
            ->toBase()
            ->first();

        // Read through `toBase()` and coerced field by field. The alternative — letting
        // Eloquent hydrate a model out of a row that is nothing but aggregates — gives
        // back an object whose `method` is cast to an enum and whose `count` does not
        // exist on the model at all.
        $values = (array) $row;

        return [
            'invoice_count' => $this->intFrom($values, 'invoice_count'),
            'gross' => $this->intFrom($values, 'gross'),
            'discount' => $this->intFrom($values, 'discount'),
            'vat' => $this->intFrom($values, 'vat'),
            'shipping' => $this->intFrom($values, 'shipping'),
            'rounding' => $this->intFrom($values, 'rounding'),
            'net' => $this->intFrom($values, 'net'),
        ];
    }

    /**
     * Every tender taken today, grouped by method.
     *
     * Keyed on the payment's own `received_at` rather than the invoice's date: a payment
     * recorded today against yesterday's invoice is today's money, and the drawer does
     * not care which paper it belongs to.
     *
     * @return array<string, array{count: int, amount: int}>
     */
    private function paymentBreakdown(CarbonImmutable $from, CarbonImmutable $to, ?int $branchId): array
    {
        $rows = InvoicePayment::query()
            ->join('sales_invoices', 'sales_invoices.id', '=', 'invoice_payments.sales_invoice_id')
            ->where('sales_invoices.status', InvoiceStatus::Final->value)
            ->whereBetween('invoice_payments.received_at', [$from, $to])
            ->when($branchId !== null, fn ($query) => $query->where('sales_invoices.branch_id', $branchId))
            ->groupBy('invoice_payments.method')
            ->selectRaw('invoice_payments.method, count(*) as count, coalesce(sum(invoice_payments.amount), 0) as amount')
            ->toBase()
            ->get();

        $breakdown = [];

        foreach ($rows as $row) {
            $values = (array) $row;
            $method = $values['method'] ?? null;

            if (! is_string($method)) {
                continue;
            }

            $breakdown[$method] = [
                'count' => $this->intFrom($values, 'count'),
                'amount' => $this->intFrom($values, 'amount'),
            ];
        }

        return $breakdown;
    }

    /**
     * Where today's settled money landed, by account.
     *
     * @return list<array{account_id: int|null, name: string, amount: int}>
     */
    private function accountRows(CarbonImmutable $from, CarbonImmutable $to, ?int $branchId): array
    {
        $rows = InvoicePayment::query()
            ->join('sales_invoices', 'sales_invoices.id', '=', 'invoice_payments.sales_invoice_id')
            ->leftJoin('accounts', 'accounts.id', '=', 'invoice_payments.account_id')
            ->where('sales_invoices.status', InvoiceStatus::Final->value)
            ->whereNotNull('invoice_payments.account_id')
            // Only the methods that actually put money in the named account. A cheque
            // carrying a stale account id would otherwise be added to the cash box it
            // never reached, and this panel sits directly under the expected-cash
            // figure it would then contradict. `DraftInvoiceWriter` already strips
            // those ids; this is the second lock on the same door.
            ->whereIn('invoice_payments.method', $this->settlingMethods())
            ->whereBetween('invoice_payments.received_at', [$from, $to])
            ->when($branchId !== null, fn ($query) => $query->where('sales_invoices.branch_id', $branchId))
            ->groupBy('invoice_payments.account_id', 'accounts.name')
            ->selectRaw('invoice_payments.account_id, accounts.name, coalesce(sum(invoice_payments.amount), 0) as amount')
            ->orderByDesc('amount')
            ->toBase()
            ->get()
            ->all();

        return array_values(array_map(function (mixed $row): array {
            $values = (array) $row;
            $name = $values['name'] ?? null;
            $accountId = $values['account_id'] ?? null;

            return [
                'account_id' => is_numeric($accountId) ? (int) $accountId : null,
                'name' => is_string($name) && $name !== '' ? $name : 'بدون حساب',
                'amount' => $this->intFrom($values, 'amount'),
            ];
        }, $rows));
    }

    /**
     * The methods that put money somewhere nameable, as raw values for a query.
     *
     * @return list<string>
     */
    private function settlingMethods(): array
    {
        return array_values(array_map(
            fn (PaymentMethod $method): string => $method->value,
            array_filter(PaymentMethod::cases(), fn (PaymentMethod $method): bool => $method->settlesImmediately()),
        ));
    }

    /**
     * @param  array<string, array{count: int, amount: int}>  $breakdown
     * @return list<array{method: string, label: string, settles_now: bool, count: int, amount: int}>
     */
    private function paymentRows(array $breakdown): array
    {
        $rows = [];

        // Every method, in enum order, so the report has the same shape every day — a
        // row that disappears when nobody used cheques is a row somebody stops looking
        // for.
        foreach (PaymentMethod::cases() as $method) {
            $found = $breakdown[$method->value] ?? ['count' => 0, 'amount' => 0];

            $rows[] = [
                'method' => $method->value,
                'label' => $method->labelFa(),
                'settles_now' => $method->settlesImmediately(),
                'count' => $found['count'],
                'amount' => $found['amount'],
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, array{count: int, amount: int}>  $breakdown
     */
    private function amountFor(array $breakdown, PaymentMethod $method): int
    {
        return $breakdown[$method->value]['amount'] ?? 0;
    }

    /**
     * Everything that settled an invoice today, by any means.
     *
     * @param  array<string, array{count: int, amount: int}>  $breakdown
     */
    private function settledTotal(array $breakdown): int
    {
        $total = 0;

        foreach ($breakdown as $method => $row) {
            if ($method !== PaymentMethod::Credit->value) {
                $total += $row['amount'];
            }
        }

        return $total;
    }

    private function refunded(CarbonImmutable $from, CarbonImmutable $to, ?int $branchId): int
    {
        return (int) SalesReturn::query()
            ->join('sales_invoices', 'sales_invoices.id', '=', 'sales_returns.sales_invoice_id')
            // Only the ones that actually opened the drawer. A refund credited to a
            // customer's account moved no cash, and subtracting it from the expected
            // till would make the drawer look short by exactly that amount.
            ->whereNull('sales_returns.party_id')
            ->whereBetween('sales_returns.returned_at', [$from, $to])
            ->when($branchId !== null, fn ($query) => $query->where('sales_invoices.branch_id', $branchId))
            ->sum('sales_returns.total');
    }

    /**
     * One aggregate column, coerced once.
     *
     * Postgres returns `sum()` as a string for bigint columns, so every one of these
     * needs the same two lines — written here rather than fourteen times.
     *
     * @param  array<array-key, mixed>  $values
     */
    private function intFrom(array $values, string $key): int
    {
        $value = $values[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function voidCount(CarbonImmutable $from, CarbonImmutable $to, ?int $branchId): int
    {
        return SalesInvoice::query()
            ->where('status', InvoiceStatus::Void->value)
            ->whereBetween('voided_at', [$from, $to])
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->count();
    }

    private function returnCount(CarbonImmutable $from, CarbonImmutable $to, ?int $branchId): int
    {
        return SalesReturn::query()
            ->join('sales_invoices', 'sales_invoices.id', '=', 'sales_returns.sales_invoice_id')
            ->whereBetween('sales_returns.returned_at', [$from, $to])
            ->when($branchId !== null, fn ($query) => $query->where('sales_invoices.branch_id', $branchId))
            ->count();
    }
}
