<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\Account;
use App\Modules\Inventory\Services\BranchContext;
use App\Modules\Treasury\Services\AccountBalances;
use App\Modules\Treasury\Services\AccountStatement;
use App\Modules\Treasury\Services\DailyClose;
use App\Modules\Treasury\Services\ProfitAndLoss;
use App\Modules\Treasury\Services\ReconcileEntries;
use App\Modules\Treasury\Services\TransferBetweenAccounts;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The money screens: what is where, what moved, and what the day came to.
 *
 * Accounts that hold money are listed separately from headings that merely classify it —
 * asking how much is "in" the rent account is a category error, and a page that lists them
 * together invites it.
 */
final class TreasuryController extends Controller
{
    public function index(Request $request, AccountBalances $balances, BranchContext $context): Response
    {
        $this->authorize('viewAny', Account::class);

        /*
        | `includeUnassigned` is doing real work here. Most accounts belong to no branch —
        | the shop's bank account, its sales income account — and only a till or a card
        | terminal is genuinely per-counter. Filtering those out when a branch is selected
        | would hide the shop's bank balance from the treasury page, which is the one figure
        | it exists to show.
        */
        $accounts = $context
            ->apply(
                Account::query()->where('is_active', true)->orderBy('type')->orderBy('name'),
                'accounts.branch_id',
                includeUnassigned: true,
            )
            ->get();
        $figures = $balances->balances();

        $held = $accounts->filter(fn (Account $a): bool => $a->holdsMoney());

        return Inertia::render('Treasury::Treasury/Index', [
            'accounts' => $held->map(fn (Account $account): array => [
                'id' => $account->id,
                'name' => $account->name,
                'type' => $account->type,
                'bank_name' => $account->bank_name,
                'balance' => Money::toArray($figures[$account->id] ?? 0),
                'unreconciled' => Money::toArray($balances->unreconciledTotal($account)),
            ])->values()->all(),
            // Headings, shown apart and without a "balance" label, so nobody reads a
            // classification as a place money sits.
            'headings' => $accounts->reject(fn (Account $a): bool => $a->holdsMoney())
                ->map(fn (Account $account): array => [
                    'id' => $account->id,
                    'name' => $account->name,
                    'type' => $account->type,
                    'total' => Money::toArray(abs($figures[$account->id] ?? 0)),
                ])->values()->all(),
            'total' => Money::toArray($held->sum(fn (Account $a): int => $figures[$a->id] ?? 0)),
        ]);
    }

    public function statement(Request $request, Account $account, AccountStatement $statements): Response
    {
        $this->authorize('view', $account);

        $statement = $statements->for($account);
        $paginator = $statement['entries'];

        return Inertia::render('Treasury::Treasury/Statement', [
            'account' => [
                'id' => $account->id,
                'name' => $account->name,
                'type' => $account->type,
                'holds_money' => $account->holdsMoney(),
            ],
            'opening' => Money::toArray($statement['opening']),
            'closing' => Money::toArray($statement['closing']),
            'entries' => [
                'data' => collect($paginator->items())->map(fn ($entry): array => [
                    'id' => $entry->id,
                    'description' => $entry->description,
                    'debit' => Money::toArray($entry->debit),
                    'credit' => Money::toArray($entry->credit),
                    'running' => Money::toArray($this->intOf($entry->getAttribute('running_balance'))),
                    'occurred_at' => $entry->occurred_at->toIso8601String(),
                    'reconciled' => $entry->reconciled_at !== null,
                    'actor' => $entry->actor?->name,
                ])->values()->all(),
                'links' => $paginator->linkCollection()->toArray(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function transfer(Request $request, TransferBetweenAccounts $transfers): RedirectResponse
    {
        $this->authorize('create', Account::class);

        $data = $request->validate([
            'from_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'to_account_id' => ['required', 'integer', 'different:from_account_id', 'exists:accounts,id'],
            'amount' => ['required', 'integer', 'min:1'],
            'fee' => ['nullable', 'integer', 'min:0'],
            'reference' => ['nullable', 'string', 'max:160'],
        ]);

        /** @var Account $from */
        $from = Account::query()->findOrFail($data['from_account_id']);
        /** @var Account $to */
        $to = Account::query()->findOrFail($data['to_account_id']);

        try {
            $transfers->transfer(
                $from, $to, (int) $data['amount'],
                fee: (int) ($data['fee'] ?? 0),
                reference: $data['reference'] ?? null,
                actorId: $request->user()?->id,
            );
        } catch (RuntimeException $exception) {
            // Beside the form, where the person who typed the amount is looking.
            return back()->withErrors(['transfer' => $exception->getMessage()]);
        }

        return back()->with('status', 'انتقال ثبت شد.');
    }

    public function reconcile(Request $request, Account $account, ReconcileEntries $reconciler): RedirectResponse
    {
        $this->authorize('update', $account);

        $data = $request->validate([
            'entry_ids' => ['required', 'array'],
            'entry_ids.*' => ['integer'],
            'undo' => ['nullable', 'boolean'],
        ]);

        /** @var list<int> $ids */
        $ids = array_values(array_map('intval', $data['entry_ids']));

        try {
            ($data['undo'] ?? false)
                ? $reconciler->unreconcile($account, $ids)
                : $reconciler->reconcile($account, $ids, (int) $request->user()?->id);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['reconcile' => $exception->getMessage()]);
        }

        return back()->with('status', 'مغایرت‌گیری به‌روزرسانی شد.');
    }

    public function close(Request $request, DailyClose $close, ProfitAndLoss $pnl): Response
    {
        $this->authorize('viewAny', Account::class);

        $day = $request->filled('date')
            ? CarbonImmutable::parse($request->string('date')->value())
            : CarbonImmutable::now();

        $report = $close->for($day);
        $period = $pnl->forPeriod($day->startOfMonth(), $day->endOfMonth());

        return Inertia::render('Treasury::Treasury/Close', [
            'date' => $report['date'],
            'accounts' => array_map(fn (array $row): array => [
                ...$row,
                'opening' => Money::toArray($row['opening']),
                'movement' => Money::toArray($row['movement']),
                'closing' => Money::toArray($row['closing']),
                'unreconciled' => Money::toArray($row['unreconciled']),
            ], $report['accounts']),
            'totals' => [
                'opening' => Money::toArray($report['totals']['opening']),
                'movement' => Money::toArray($report['totals']['movement']),
                'closing' => Money::toArray($report['totals']['closing']),
            ],
            'pnl' => [
                'from' => $period['from'],
                'to' => $period['to'],
                'revenue' => Money::toArray($period['revenue']),
                'cost_of_goods' => Money::toArray($period['cost_of_goods']),
                'gross_margin' => Money::toArray($period['gross_margin']),
                'other_income' => Money::toArray($period['other_income']),
                'operating_costs' => Money::toArray($period['operating_costs']),
                'net_profit' => Money::toArray($period['net_profit']),
                'expense_breakdown' => array_map(fn (array $row): array => [
                    'category' => $row['category'],
                    'amount' => Money::toArray($row['amount']),
                ], $period['expense_breakdown']),
            ],
        ]);
    }

    /**
     * A value the query builder hands back as `mixed`, as an integer.
     */
    private function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
