<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\Party;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Branch;
use App\Modules\Inventory\Services\BranchAccess;
use App\Modules\Sales\Enums\InvoiceStatus;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Exceptions\UnitNoLongerAvailable;
use App\Modules\Sales\Http\Requests\PosSaleRequest;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DraftInvoiceWriter;
use App\Modules\Sales\Services\FinaliseInvoice;
use App\Modules\Sales\Services\PosScanner;
use App\Support\Money;
use App\Support\Settings\ShopSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The till.
 *
 * The screen a shopkeeper sees a hundred times a day, which is the only design
 * constraint that matters here. Three consequences run through this controller:
 *
 * 1. **`scan` is the hot path.** It is the one request the operator waits on, once per
 *    line, so it does the least work that answers the question and nothing else.
 * 2. **`store` is the whole sale.** The basket lives in the browser (see
 *    {@see PosSaleRequest}) and arrives once, either parked as a draft or issued.
 * 3. **A refusal has to be readable while a customer is standing there.** Every failure
 *    path here ends in a Persian sentence naming the thing that went wrong, thrown back
 *    as a validation error onto the field that caused it — never a 500, and never a
 *    generic "something went wrong".
 */
final class PosController extends Controller
{
    public function __construct(
        private readonly BranchAccess $branches,
        private readonly ShopSettings $settings,
    ) {}

    /**
     * Open the till, optionally on a parked draft.
     */
    public function create(Request $request, ?SalesInvoice $invoice = null): Response
    {
        $this->authorize('create', SalesInvoice::class);

        /** @var User $user The `tenant.user` middleware guarantees a tenant user here. */
        $user = $request->user();

        $branch = $this->resolveBranch($request, $invoice);

        if ($invoice instanceof SalesInvoice && ! $invoice->isDraft()) {
            abort(404);
        }

        $vat = $this->settings->vat();

        return Inertia::render('Sales::Pos/Index', [
            'invoice' => $invoice instanceof SalesInvoice ? $this->resumePayload($invoice) : null,
            'branch' => [
                'id' => $branch->id,
                'name' => $branch->name,
            ],
            'branches' => $this->branchOptions($user),
            'salesperson' => ['id' => $user->id, 'name' => $user->name],
            'accounts' => $this->accountOptions(),
            'payment_methods' => $this->paymentMethodOptions(),
            'vat' => [
                'rate' => $vat->rate,
                'enabled' => $vat->enabled,
            ],
            'rounding' => [
                'step' => $this->settings->rounding()->step,
                'direction' => $this->settings->rounding()->direction->value,
            ],
            'can' => [
                'view_profit' => $user->can('sales.view_profit'),
                'view_cost' => $user->can('inventory.view_cost'),
            ],
        ]);
    }

    /**
     * Resolve whatever came out of the scanner.
     *
     * Deliberately a plain JSON endpoint rather than an Inertia partial reload: the
     * result does not change the page, it appends a row to a basket the browser owns,
     * and a full prop round-trip per scan is exactly the latency this screen cannot
     * afford.
     */
    public function scan(Request $request, PosScanner $scanner): JsonResponse
    {
        $this->authorize('create', SalesInvoice::class);

        $branch = $this->resolveBranch($request);

        $candidates = $scanner->resolve(
            code: $request->string('code')->value(),
            branchId: $branch->id,
            priceLevelId: $this->priceLevelFor($request),
            showCost: $request->user()?->can('inventory.view_cost') ?? false,
        );

        return response()->json([
            'results' => array_map(function (array $candidate): array {
                $price = $candidate['unit_price'];
                $cost = $candidate['cost'];

                $candidate['unit_price'] = Money::toArray(is_int($price) ? $price : 0);
                $candidate['cost'] = is_int($cost) ? Money::toArray($cost) : null;

                return $candidate;
            }, $candidates),
        ]);
    }

    /**
     * Park the basket, or sell it.
     *
     * Creating the draft and issuing it are two steps rather than one because they are
     * two different transactions with different guarantees: the draft is a write the
     * operator can retry freely, and finalisation takes stock, a number and a ledger
     * entry, and must be all-or-nothing on its own.
     */
    public function store(PosSaleRequest $request, DraftInvoiceWriter $writer, FinaliseInvoice $finaliser): RedirectResponse
    {
        $this->authorize('create', SalesInvoice::class);

        /** @var User $user */
        $user = $request->user();

        /** @var Branch $branch */
        $branch = Branch::query()->findOrFail($request->integer('branch_id'));

        // Not merely a policy check: `branch_user` is how a two-branch shop stops a
        // salesperson selling out of the other branch's stock (Phase 3.1).
        if (! $this->branches->canUse($user, $branch->id)) {
            throw ValidationException::withMessages([
                'branch_id' => 'شما به این شعبه دسترسی ندارید.',
            ]);
        }

        $invoice = SalesInvoice::query()->create([
            'branch_id' => $branch->id,
            'party_id' => $request->integer('party_id') ?: null,
            'salesperson_id' => $request->integer('salesperson_id') ?: $user->id,
            'type' => SalesInvoice::TYPE_INVOICE,
            // Stated rather than inferred, even though the model now defaults it: what
            // this endpoint creates is a draft, and saying so here is what makes the
            // two-step (write the basket, then finalise) readable.
            'status' => InvoiceStatus::Draft,
            'discount_amount' => $request->invoiceDiscount(),
            'shipping_amount' => $request->shipping(),
            'notes' => $request->string('notes')->value() ?: null,
            // Snapshotted at creation, not at issue: a basket parked this afternoon and
            // sold tomorrow morning must round and tax the way it was quoted, even if
            // the shop changes its settings overnight.
            'settings_snapshot' => [
                ...$this->settings->rounding()->toSnapshot(),
                ...$this->settings->vat()->toSnapshot(),
                'vat_enabled' => $request->boolean('vat_applied'),
            ],
        ]);

        try {
            $writer->write($invoice, $request->lines(), $request->payments(), $user->id);

            if (! $request->shouldFinalise()) {
                return redirect()
                    ->route('sales.invoices.show', $invoice)
                    ->with('success', 'فاکتور به‌عنوان پیش‌نویس ذخیره شد.');
            }

            $finaliser->finalise($invoice, $user->id);
        } catch (UnitNoLongerAvailable $exception) {
            // The race, lost. The draft is deleted rather than left behind: it is a
            // basket that can never be sold, and a till whose parked-drafts list fills up
            // with unsellable ghosts is one nobody trusts.
            $invoice->forceDelete();

            throw ValidationException::withMessages(['lines' => $exception->getMessage()]);
        } catch (RuntimeException $exception) {
            $invoice->forceDelete();

            throw ValidationException::withMessages(['lines' => $exception->getMessage()]);
        }

        return redirect()
            ->route('sales.invoices.show', $invoice)
            ->with('success', "فاکتور {$invoice->number} ثبت شد.");
    }

    /**
     * Which branch this till is selling from.
     *
     * A resumed draft keeps its own branch — its stock was checked against that
     * warehouse and its number belongs to that branch's sequence.
     */
    private function resolveBranch(Request $request, ?SalesInvoice $invoice = null): Branch
    {
        if ($invoice instanceof SalesInvoice) {
            return $invoice->branch;
        }

        /** @var User $user */
        $user = $request->user();

        if ($request->filled('branch_id') && $this->branches->canUse($user, $request->integer('branch_id'))) {
            /** @var Branch $selected */
            $selected = Branch::query()->findOrFail($request->integer('branch_id'));

            return $selected;
        }

        $branch = $this->branches->defaultFor($user);

        // Provisioning gives every shop a branch and a warehouse inside the signup
        // transaction (Phase 3.1), so this is a broken installation rather than a new one.
        abort_if($branch === null, 409, 'این کاربر به هیچ شعبه‌ای دسترسی ندارد.');

        return $branch;
    }

    /**
     * The customer's price level, so the till quotes همکار prices to a همکار.
     */
    private function priceLevelFor(Request $request): ?int
    {
        if (! $request->filled('party_id')) {
            return null;
        }

        $level = Party::query()->whereKey($request->integer('party_id'))->value('price_level_id');

        return is_int($level) ? $level : null;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function branchOptions(User $user): array
    {
        $allowed = $this->branches->allowedFor($user);

        $branches = Branch::query()
            ->where('is_active', true)
            // A null means every branch — restriction is opt-in (Phase 3.1).
            ->when($allowed !== null, fn ($query) => $query->whereIn('id', $allowed))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->all();

        return array_values(array_map(
            fn (Branch $branch): array => ['id' => $branch->id, 'name' => $branch->name],
            $branches,
        ));
    }

    /**
     * Where money can land — cash boxes, terminals and banks.
     *
     * The internal accounts are excluded on purpose: `inventory` and `sales` are
     * bookkeeping subjects, not places a customer's money goes, and offering them at the
     * till would let a cashier post a sale's cash straight into revenue twice.
     *
     * @return list<array{id: int, name: string, type: string, is_default: bool}>
     */
    private function accountOptions(): array
    {
        $accounts = Account::query()
            ->where('is_active', true)
            ->whereIn('type', [Account::TYPE_CASH, Account::TYPE_BANK, Account::TYPE_POS_TERMINAL])
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'is_default'])
            ->all();

        return array_values(array_map(fn (Account $account): array => [
            'id' => $account->id,
            'name' => $account->name,
            'type' => $account->type,
            'is_default' => $account->is_default,
        ], $accounts));
    }

    /**
     * @return list<array{value: string, label: string, needs_account: bool, needs_reference: bool}>
     */
    private function paymentMethodOptions(): array
    {
        return array_map(fn (PaymentMethod $method): array => [
            'value' => $method->value,
            'label' => $method->labelFa(),
            'needs_account' => $method->needsAccount(),
            // The evidence the shop is asked for when a payment is disputed weeks later.
            'needs_reference' => in_array(
                $method,
                [PaymentMethod::PosTerminal, PaymentMethod::CardToCard, PaymentMethod::Cheque],
                true,
            ),
        ], PaymentMethod::cases());
    }

    /**
     * A parked draft, in the shape the till's own state uses.
     *
     * @return array<string, mixed>
     */
    private function resumePayload(SalesInvoice $invoice): array
    {
        $invoice->load(['items', 'payments', 'party']);

        return [
            'id' => $invoice->id,
            'party' => $invoice->party === null ? null : [
                'id' => $invoice->party->id,
                'name' => $invoice->party->name,
                'company_name' => $invoice->party->company_name,
                'kind' => $invoice->party->kind->value,
                'kind_label' => $invoice->party->kind->labelFa(),
                'mobile' => $invoice->party->primaryMobile(),
                'balance' => null,
            ],
            'notes' => $invoice->notes,
            'discount_amount' => $invoice->discount_amount,
            'shipping_amount' => $invoice->shipping_amount,
            'vat_applied' => ($invoice->settings_snapshot['vat_enabled'] ?? false) === true,
            'lines' => $invoice->items->map(fn ($item): array => [
                'key' => $item->product_unit_id !== null
                    ? "unit:{$item->product_unit_id}"
                    : "variant:{$item->product_variant_id}",
                'kind' => $item->product_unit_id !== null ? 'unit' : 'variant',
                'unit_id' => $item->product_unit_id,
                'variant_id' => $item->product_variant_id,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'discount_amount' => $item->discount_amount,
                'warranty_months' => $item->warranty_months,
            ])->all(),
            'payments' => $invoice->payments->map(fn ($payment): array => [
                'method' => $payment->method->value,
                'amount' => $payment->amount,
                'tendered_amount' => $payment->tendered_amount,
                'account_id' => $payment->account_id,
                'reference' => $payment->reference,
            ])->all(),
        ];
    }
}
