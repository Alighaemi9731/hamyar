<?php

declare(strict_types=1);

namespace App\Modules\Cheques\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cheques\Enums\ChequeDirection;
use App\Modules\Cheques\Models\Cheque;
use App\Modules\Cheques\Services\ChequeCalendar;
use App\Modules\Cheques\Services\ChequeTransitions;
use App\Modules\CRM\Models\Account;
use App\Modules\Inventory\Services\BranchContext;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The cheque book, and the list a shop opens every morning.
 *
 * Issued cheques lead, because a missed one is the shop's own credit failing in public
 * rather than merely an opportunity forgone.
 */
final class ChequeController extends Controller
{
    public function index(Request $request, ChequeCalendar $calendar, BranchContext $context): Response
    {
        $this->authorize('viewAny', Cheque::class);

        $direction = ChequeDirection::tryFrom($request->string('direction')->value())
            ?? ChequeDirection::Received;

        $upcoming = $calendar->upcoming($direction, days: 14);

        $all = $context
            ->apply(
                Cheque::query()
                    ->with(['party:id,name', 'account:id,name'])
                    ->where('direction', $direction->value)
                    ->orderByDesc('due_date'),
                'cheques.branch_id',
                includeUnassigned: true,
            )
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('Cheques::Cheques/Index', [
            'direction' => $direction->value,
            'overdue' => array_map($this->row(...), $upcoming['overdue']),
            'due' => array_map($this->row(...), $upcoming['due']),
            'totals' => [
                'overdue' => Money::toArray($upcoming['total_overdue']),
                'due' => Money::toArray($upcoming['total_due']),
            ],
            'cheques' => [
                'data' => array_map($this->row(...), $all->items()),
                'links' => $all->linkCollection()->toArray(),
                'total' => $all->total(),
            ],
            'accounts' => Account::query()
                ->where('type', Account::TYPE_BANK)
                ->where('is_active', true)
                ->get(['id', 'name'])
                ->map(fn (Account $a): array => ['id' => $a->id, 'name' => $a->name])
                ->all(),
        ]);
    }

    /**
     * Move a cheque along its lifecycle.
     *
     * One endpoint per verb would be five near-identical controllers; one endpoint with an
     * action is honest about what this is — a state machine with a small vocabulary. Every
     * failure comes back as a message beside the row, because every guard in
     * {@see ChequeTransitions} throws a sentence a shopkeeper can act on.
     */
    public function transition(Request $request, Cheque $cheque, ChequeTransitions $transitions): RedirectResponse
    {
        $this->authorize('update', $cheque);

        $data = $request->validate([
            'action' => ['required', 'string', 'in:deposit,clear,bounce,endorse,return,write_off'],
            'account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'party_id' => ['nullable', 'integer', 'exists:parties,id'],
            'reason' => ['nullable', 'string', 'max:160'],
            'fee' => ['nullable', 'integer', 'min:0'],
            'recovered' => ['nullable', 'integer', 'min:0'],
            'occurred_on' => ['nullable', 'date'],
        ]);

        $at = isset($data['occurred_on'])
            ? CarbonImmutable::parse($data['occurred_on'])
            : CarbonImmutable::now();

        $account = isset($data['account_id'])
            ? Account::query()->whereKey($data['account_id'])->first()
            : null;

        $actorId = $request->user()?->id;

        try {
            match ($data['action']) {
                'deposit' => $transitions->deposit(
                    $cheque,
                    $account instanceof Account ? $account : throw new RuntimeException('حساب بانکی مقصد را انتخاب کنید.'),
                    $at, $actorId,
                ),
                'clear' => $transitions->clear($cheque, $account, (int) ($data['fee'] ?? 0), $at, $actorId),
                'bounce' => $transitions->bounce(
                    $cheque,
                    $data['reason'] ?? 'کسر موجودی',
                    (int) ($data['recovered'] ?? 0),
                    (int) ($data['fee'] ?? 0),
                    $at, $actorId,
                ),
                'endorse' => $transitions->endorse(
                    $cheque,
                    (int) ($data['party_id'] ?? throw new RuntimeException('طرف حساب گیرنده را انتخاب کنید.')),
                    $at, $actorId,
                ),
                'return' => $transitions->returnToDrawer($cheque, $at, $actorId),
                'write_off' => $transitions->writeOff($cheque, $at, $actorId),
                default => throw new RuntimeException('این عملیات شناخته نشد.'),
            };
        } catch (RuntimeException $exception) {
            return back()->withErrors(['cheque' => $exception->getMessage()]);
        }

        return back()->with('status', 'وضعیت چک به‌روزرسانی شد.');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Cheque $cheque): array
    {
        return [
            'id' => $cheque->id,
            'serial' => $cheque->serial,
            'bank_name' => $cheque->bank_name,
            'party_name' => $cheque->party?->name,
            'account_name' => $cheque->account?->name,
            'amount' => Money::toArray($cheque->amount),
            'outstanding' => Money::toArray($cheque->outstanding()),
            'status' => $cheque->status->value,
            'status_label' => $cheque->status->labelFa(),
            'due_date' => $cheque->due_date->toIso8601String(),
            'attempt' => $cheque->presentation_attempt,
        ];
    }
}
