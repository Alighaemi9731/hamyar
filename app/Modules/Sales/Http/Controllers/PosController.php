<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
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
use App\Modules\Sales\Services\PaymentOptions;
use App\Modules\Sales\Services\PosScanner;
use App\Support\Counters\CounterService;
use App\Support\Money;
use App\Support\Quota\QuotaGuard;
use App\Support\Settings\ShopSettings;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\ConnectionInterface;
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
        private readonly TenantContext $context,
        private readonly PaymentOptions $options,
        private readonly ConnectionInterface $connection,
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
            'accounts' => $this->options->accounts(),
            'payment_methods' => $this->options->methods(),
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
    public function store(
        PosSaleRequest $request,
        DraftInvoiceWriter $writer,
        FinaliseInvoice $finaliser,
        CounterService $counters,
        QuotaGuard $quota,
    ): RedirectResponse {
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

        $isQuote = $request->isQuote();

        $invoice = SalesInvoice::query()->create([
            'branch_id' => $branch->id,
            'party_id' => $request->integer('party_id') ?: null,
            'salesperson_id' => $request->integer('salesperson_id') ?: $user->id,
            'type' => $isQuote ? SalesInvoice::TYPE_QUOTE : SalesInvoice::TYPE_INVOICE,
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
                ...$this->settings->print()->toSnapshot(),
                ...$this->settings->commission()->toSnapshot(),
                'vat_enabled' => $request->boolean('vat_applied'),
            ],
        ]);

        $tradeIn = $isQuote ? null : $request->tradeIn();

        try {
            // A quote takes no payments: nobody has paid anything against a promise.
            // Silently dropping them beats refusing the submit, because the payment box
            // may simply have been left half-filled when somebody chose «پیش‌فاکتور».
            $writer->write(
                $invoice,
                $request->lines(),
                // The trade-in rides in as an ordinary tender so the writer can order and
                // clamp the whole set together — see DraftInvoiceWriter.
                $isQuote ? [] : $this->tenders($invoice, $request->payments(), $tradeIn),
                $user->id,
            );

            if ($isQuote) {
                /*
                | Numbered on creation, unlike a draft: this one gets printed and handed
                | over, and the customer quotes the number back a week later.
                |
                | The number and the credit are taken together, in a transaction. Before
                | Phase 12 this counter call ran with none — `CounterService::next()`
                | requires one and throws without it, so the quote path was one concurrent
                | pair of requests away from two quotes sharing a number. The quota work
                | is what surfaced it; the transaction fixes both.
                */
                $this->connection->transaction(function () use ($invoice, $counters, $branch, $quota): void {
                    $number = $counters->nextFormatted(
                        $this->context->idOrFail(),
                        'sales_quote',
                        'QUO',
                        $branch->id,
                    );

                    $quota->consume('sales.quotes');

                    $invoice->forceFill(['number' => $number])->save();
                });

                return redirect()
                    ->route('sales.invoices.show', $invoice)
                    ->with('success', "پیش‌فاکتور {$invoice->number} ثبت شد.");
            }

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
     * Record the معاوضه, and add the tender that settles part of the sale with it.
     *
     * The tender is built here rather than accepted from the browser because it must
     * equal the agreed price exactly: a customer who is told their phone is worth
     * 20,000,000 and gets 19,000,000 off is a customer arguing at the counter, and the
     * same figure living in two places is how the two drift apart.
     *
     * The invoice total is untouched — a trade-in is a tender, not a discount, so VAT
     * still follows the price of the handset actually being sold.
     *
     * @param  list<array{method: string, amount: int, tendered_amount: int|null, account_id: int|null, reference: string|null}>  $payments
     * @param  array{device_name: string, product_variant_id: int, imei1: string|null, grade: string|null, agreed_price: int, hamta_ack: bool}|null  $tradeIn
     * @return list<array{method: string, amount: int, tendered_amount: int|null, account_id: int|null, reference: string|null}>
     */
    private function tenders(SalesInvoice $invoice, array $payments, ?array $tradeIn): array
    {
        if ($tradeIn === null) {
            return $payments;
        }

        if ($invoice->party_id === null) {
            // Golden rule 4: a handset's passport has to answer "bought from whom".
            throw ValidationException::withMessages([
                'trade_in.device_name' => 'برای ثبت معاوضه باید مشتری را انتخاب کنید — دستگاه از او خریداری می‌شود.',
            ]);
        }

        $invoice->tradeIn()->create([
            'party_id' => $invoice->party_id,
            'device_name' => $tradeIn['device_name'],
            'product_variant_id' => $tradeIn['product_variant_id'],
            'imei1' => $tradeIn['imei1'],
            'condition' => 'used',
            'grade' => $tradeIn['grade'],
            'agreed_price' => $tradeIn['agreed_price'],
            'hamta_ack' => $tradeIn['hamta_ack'],
        ]);

        return [
            [
                'method' => PaymentMethod::TradeIn->value,
                'amount' => $tradeIn['agreed_price'],
                'tendered_amount' => null,
                'account_id' => null,
                // The IMEI, so the payment row on a printed invoice names the device that
                // settled it rather than just saying «معاوضه».
                'reference' => $tradeIn['imei1'],
            ],
            ...$payments,
        ];
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
