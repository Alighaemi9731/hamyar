<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Http\Requests\LandedCostRequest;
use App\Modules\Purchasing\Http\Requests\OpenPurchaseInvoiceRequest;
use App\Modules\Purchasing\Http\Requests\PurchaseLineRequest;
use App\Modules\Purchasing\Http\Requests\UnitBatchRequest;
use App\Modules\Purchasing\Models\LandedCost;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\PurchaseInvoiceItem;
use App\Modules\Purchasing\Models\PurchaseUnitItem;
use App\Modules\Purchasing\Services\ImeiBatchParser;
use App\Modules\Purchasing\Services\PurchaseInvoiceDraft;
use App\Modules\Purchasing\Services\ReceivePurchaseInvoice;
use App\Support\Digits;
use App\Support\Documents\DocumentRegistry;
use App\Support\Documents\DocumentType;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Shipments: building one, and turning it into stock.
 *
 * The screen this module exists for is the intake: paste twenty IMEIs, see a verdict
 * per line, fix what is wrong, and receive. Everything the parser decides is decided
 * again on the server when the batch is committed — the browser's verdicts are a
 * preview, and a client that chooses which IMEIs are acceptable is a client that can
 * register the same handset twice.
 */
final class PurchaseInvoiceController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request, DocumentRegistry $documents): Response
    {
        $this->authorize('viewAny', PurchaseInvoice::class);

        $invoices = PurchaseInvoice::query()
            ->with('warehouse:id,name')
            ->withCount(['items', 'unitItems'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->value()))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        /** @var list<int> $partyIds */
        $partyIds = collect($invoices->items())
            ->map(fn (PurchaseInvoice $invoice): ?int => $invoice->party_id)
            ->filter()->values()->all();

        $parties = $documents->describeMany(DocumentType::PARTY, $partyIds);

        return Inertia::render('Purchasing::Invoices/Index', [
            'invoices' => [
                'rows' => array_map(fn (PurchaseInvoice $invoice): array => [
                    'id' => $invoice->id,
                    'number' => $invoice->number,
                    'status' => $invoice->status,
                    'supplier' => $parties[$invoice->party_id ?? 0]->label ?? null,
                    'warehouse' => $invoice->warehouse->name,
                    'line_count' => $this->countOf($invoice, 'items_count')
                        + $this->countOf($invoice, 'unit_items_count'),
                    'total' => Money::toArray($invoice->total),
                    'issued_at' => $invoice->issued_at?->toIso8601String(),
                    'received_at' => $invoice->received_at?->toIso8601String(),
                ], $invoices->items()),
                'links' => $invoices->linkCollection()->toArray(),
                'total' => $invoices->total(),
            ],
            'filters' => ['status' => $request->string('status')->value() ?: null],
            'warehouses' => $this->warehouseOptions(),
        ]);
    }

    public function store(OpenPurchaseInvoiceRequest $request, PurchaseInvoiceDraft $draft): RedirectResponse
    {
        $this->authorize('create', PurchaseInvoice::class);

        /** @var Warehouse $warehouse */
        $warehouse = Warehouse::query()->findOrFail($request->integer('warehouse_id'));

        $invoice = $draft->open(
            $warehouse,
            $request->integer('party_id') ?: null,
            $request->user()?->id,
        );

        return redirect()
            ->route('purchasing.invoices.edit', $invoice)
            ->with('success', "پیش‌نویس {$invoice->number} باز شد.");
    }

    public function edit(Request $request, PurchaseInvoice $invoice, DocumentRegistry $documents): Response
    {
        $this->authorize('view', $invoice);

        $invoice->load([
            'items.variant.product:id,name',
            'unitItems.variant.product:id,name',
            'landedCosts',
            'warehouse:id,name',
        ]);

        return Inertia::render('Purchasing::Invoices/Edit', [
            'invoice' => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'status' => $invoice->status,
                'warehouse' => $invoice->warehouse->name,
                'supplier' => $invoice->party_id === null
                    ? null
                    : $documents->describe(DocumentType::PARTY, $invoice->party_id)?->toArray(),
                'issued_at' => $invoice->issued_at?->toIso8601String(),
                'received_at' => $invoice->received_at?->toIso8601String(),
                'notes' => $invoice->notes,
                'subtotal' => Money::toArray($invoice->subtotal),
                'landed_total' => Money::toArray($invoice->landed_total),
                'total' => Money::toArray($invoice->total),
                'is_draft' => $invoice->isDraft(),
            ],
            'standard_lines' => $invoice->items->map(fn (PurchaseInvoiceItem $item): array => [
                'id' => $item->id,
                'product_name' => $item->variant->product->name,
                'variant_name' => $item->variant->displayName(),
                'quantity' => $item->quantity,
                'unit_cost' => Money::toArray($item->unit_cost),
                'line_total' => Money::toArray($item->line_total),
            ])->values()->all(),
            'unit_lines' => $invoice->unitItems->map(fn (PurchaseUnitItem $item): array => [
                'id' => $item->id,
                'product_name' => $item->variant->product->name,
                'variant_name' => $item->variant->displayName(),
                'imei1' => $item->imei1,
                'condition' => $item->condition,
                'grade' => $item->grade,
                'unit_cost' => Money::toArray($item->unit_cost),
                'product_unit_id' => $item->product_unit_id,
            ])->values()->all(),
            'landed_costs' => $invoice->landedCosts->map(fn (LandedCost $cost): array => [
                'id' => $cost->id,
                'type' => $cost->type,
                'amount' => Money::toArray($cost->amount),
                'allocation' => $cost->allocation,
                'description' => $cost->description,
            ])->values()->all(),
            'can' => [
                // Permission AND state. The policy answers only the first half, so the
                // screen has to ask the second or it offers an Owner a button that
                // every layer beneath it will refuse.
                'edit' => $invoice->isDraft() && ($request->user()?->can('update', $invoice) ?? false),
                'receive' => $invoice->isDraft() && ($request->user()?->can('receive', $invoice) ?? false),
            ],
        ]);
    }

    /**
     * Parse a pasted batch and report a verdict per line, writing nothing.
     */
    public function parseImeis(Request $request, PurchaseInvoice $invoice, ImeiBatchParser $parser): JsonResponse
    {
        $this->authorize('update', $invoice);
        $this->guardDraft($invoice);

        $validated = $request->validate([
            'imeis' => ['required', 'string', 'max:20000'],
        ], [
            'imeis.required' => 'شماره‌های IMEI را بچسبانید یا اسکن کنید.',
        ]);

        $result = $parser->parse($validated['imeis']);

        return response()->json([
            'lines' => $result['lines'],
            'counts' => $result['counts'],
            'clean' => $parser->isClean($result),
        ]);
    }

    public function storeUnits(UnitBatchRequest $request, PurchaseInvoice $invoice, PurchaseInvoiceDraft $draft): RedirectResponse
    {
        $this->authorize('update', $invoice);
        $this->guardDraft($invoice);

        $outcome = $draft->addUnitLines(
            $invoice,
            $request->string('imeis')->value(),
            $request->integer('product_variant_id'),
            $request->rial(),
            $request->string('condition')->value(),
            $request->string('grade')->value() ?: null,
            $request->boolean('skip_rejected'),
        );

        if ($outcome['committed'] === 0) {
            return back()->withErrors([
                'imeis' => 'هیچ دستگاهی ثبت نشد. ردیف‌های نامعتبر را اصلاح کنید یا گزینه «رد شده‌ها را نادیده بگیر» را بزنید.',
            ]);
        }

        return back()->with('success', Digits::toPersian((string) $outcome['committed']).' دستگاه به این فاکتور اضافه شد.');
    }

    public function storeLine(PurchaseLineRequest $request, PurchaseInvoice $invoice, PurchaseInvoiceDraft $draft): RedirectResponse
    {
        $this->authorize('update', $invoice);
        $this->guardDraft($invoice);

        $draft->addStandardLine(
            $invoice,
            $request->integer('product_variant_id'),
            $request->integer('quantity'),
            $request->rial(),
        );

        return back()->with('success', 'ردیف اضافه شد.');
    }

    public function storeLandedCost(LandedCostRequest $request, PurchaseInvoice $invoice, PurchaseInvoiceDraft $draft): RedirectResponse
    {
        $this->authorize('update', $invoice);
        $this->guardDraft($invoice);

        $draft->addLandedCost(
            $invoice,
            $request->string('type')->value(),
            $request->rial(),
            $request->string('allocation')->value(),
            $request->string('description')->value() ?: null,
        );

        return back()->with('success', 'هزینه سربار اضافه شد.');
    }

    public function destroyLine(Request $request, PurchaseInvoice $invoice, string $kind, int $line, PurchaseInvoiceDraft $draft): RedirectResponse
    {
        $this->authorize('update', $invoice);
        $this->guardDraft($invoice);

        $draft->removeLine($invoice, $kind, $line);

        return back()->with('success', 'ردیف حذف شد.');
    }

    /**
     * Turn the shipment into stock, devices and a debt — in one transaction.
     */
    public function receive(PurchaseInvoice $invoice, ReceivePurchaseInvoice $receiver): RedirectResponse
    {
        $this->authorize('receive', $invoice);
        $this->guardDraft($invoice);

        try {
            $receiver->receive($invoice);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['receive' => $exception->getMessage()]);
        }

        return back()->with('success', "فاکتور {$invoice->number} دریافت شد. کالاها وارد انبار شدند.");
    }

    /**
     * The goods-received note, on paper.
     */
    public function grn(PurchaseInvoice $invoice, DocumentRegistry $documents): Response
    {
        $this->authorize('view', $invoice);

        $invoice->load([
            'items.variant.product:id,name',
            'unitItems.variant.product:id,name',
            'warehouse.branch:id,name,address,phone',
        ]);

        return Inertia::render('Purchasing::Invoices/Grn', [
            'invoice' => [
                'number' => $invoice->number,
                'status' => $invoice->status,
                'supplier' => $invoice->party_id === null
                    ? null
                    : $documents->describe(DocumentType::PARTY, $invoice->party_id)?->label,
                'warehouse' => $invoice->warehouse->name,
                'branch' => $invoice->warehouse->branch->name,
                'branch_address' => $invoice->warehouse->branch->address,
                'branch_phone' => $invoice->warehouse->branch->phone,
                'received_at' => $invoice->received_at?->toIso8601String(),
                'issued_at' => $invoice->issued_at?->toIso8601String(),
                'subtotal' => Money::toArray($invoice->subtotal),
                'landed_total' => Money::toArray($invoice->landed_total),
                'total' => Money::toArray($invoice->total),
            ],
            'standard_lines' => $invoice->items->map(fn (PurchaseInvoiceItem $item): array => [
                'id' => $item->id,
                'name' => $item->variant->product->name.' · '.$item->variant->displayName(),
                'quantity' => $item->quantity,
                'unit_cost' => Money::toArray($item->trueUnitCost()),
                'line_total' => Money::toArray($item->trueUnitCost() * $item->quantity),
            ])->values()->all(),
            'unit_lines' => $invoice->unitItems->map(fn (PurchaseUnitItem $item): array => [
                'id' => $item->id,
                'name' => $item->variant->product->name.' · '.$item->variant->displayName(),
                'imei1' => $item->imei1,
                'unit_cost' => Money::toArray($item->trueUnitCost()),
            ])->values()->all(),
        ]);
    }

    /**
     * Variants a line can point at, for the two add-line forms.
     */
    public function variants(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PurchaseInvoice::class);

        $term = trim($request->string('q')->value());
        $serialized = $request->boolean('serialized');

        $variants = ProductVariant::query()
            ->with('product:id,name,type')
            ->where('is_active', true)
            ->whereHas('product', function ($query) use ($term, $serialized): void {
                $query->where('is_active', true)
                    ->where('type', $serialized ? 'serialized' : 'standard')
                    ->when($term !== '', fn ($q) => $q->where('name', 'ilike', "%{$term}%"));
            })
            ->orderBy('product_id')
            ->limit(20)
            ->get();

        return response()->json([
            'results' => $variants->map(fn (ProductVariant $variant): array => [
                'id' => $variant->id,
                'product_name' => $variant->product->name,
                'variant_name' => $variant->displayName(),
                'barcode' => $variant->barcode,
            ])->values()->all(),
        ]);
    }

    /**
     * Refuse anything that would edit a shipment which is already stock.
     *
     * Not in the policy, and not only in the service. The Owner `Gate::before` override
     * short-circuits every policy method, so a state check written there never runs for
     * an Owner; and by the time the service throws, the failure is a 500 rather than an
     * answer. This is the layer that turns "that document is closed" into a sentence.
     */
    private function guardDraft(PurchaseInvoice $invoice): void
    {
        abort_unless(
            $invoice->isDraft(),
            403,
            "فاکتور {$invoice->number} دریافت شده و دیگر قابل ویرایش نیست."
        );
    }

    /**
     * A `withCount` result, typed — `getAttribute()` returns mixed.
     */
    private function countOf(PurchaseInvoice $invoice, string $attribute): int
    {
        /** @var int|numeric-string $value */
        $value = $invoice->getAttribute($attribute);

        return (int) $value;
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    private function warehouseOptions(): array
    {
        $options = [];

        foreach (Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']) as $warehouse) {
            $options[] = ['id' => $warehouse->id, 'label' => $warehouse->name];
        }

        return $options;
    }
}
