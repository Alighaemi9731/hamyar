<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Enums\UnitCondition;
use App\Modules\Inventory\Enums\UnitStatus;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\ProductUnitHistory;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Support\Documents\DocumentRegistry;
use App\Support\Documents\DocumentType;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The serialized register and the IMEI passport.
 *
 * The passport is the product's signature screen. For any handset the shop has ever
 * touched it answers, in one vertical read: bought from whom → where it went → what
 * was done to it → sold to whom. No competitor in this market does it, and it is the
 * reason a shop with a working passport does not switch away.
 *
 * The history is append-only and already complete (every transition writes a row in
 * the same transaction as the status change), so this controller only has to *name*
 * things: turn each line's polymorphic reference and the acquiring party into Persian
 * labels. It does that through the shared {@see DocumentRegistry} rather than by
 * importing Purchasing or CRM (ADR 0003).
 */
final class UnitController extends Controller
{
    private const PER_PAGE = 25;

    private const SEARCH_LIMIT = 12;

    public function index(Request $request, DocumentRegistry $documents): Response
    {
        $this->authorize('viewAny', ProductUnit::class);

        $term = trim($request->string('q')->value());
        $showCost = $request->user()?->can('inventory.view_cost') ?? false;

        $units = ProductUnit::query()
            ->with(['variant.product:id,name', 'warehouse:id,name'])
            ->when($term !== '', fn ($query) => $query->where(
                fn ($q) => $q->matchingCode($term)
                    ->orWhereHas('variant.product', fn ($p) => $p->where('name', 'ilike', "%{$term}%"))
            ))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->value()))
            ->when($request->filled('warehouse_id'), fn ($query) => $query->where('warehouse_id', $request->integer('warehouse_id')))
            ->when($request->filled('condition'), fn ($query) => $query->where('condition', $request->string('condition')->value()))
            ->when($request->filled('hamta'), fn ($query) => $query->where('hamta_status', $request->string('hamta')->value()))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        /** @var list<int> $partyIds */
        $partyIds = collect($units->items())
            ->map(fn (ProductUnit $unit): ?int => $unit->acquired_from_party_id)
            ->filter()
            ->values()
            ->all();

        $parties = $documents->describeMany(DocumentType::PARTY, $partyIds);

        return Inertia::render('Inventory::Units/Index', [
            'units' => [
                'rows' => array_map(
                    fn (ProductUnit $unit): array => [
                        'id' => $unit->id,
                        'imei1' => $unit->imei1,
                        'serial' => $unit->serial,
                        'product_name' => $unit->variant->product->name,
                        'variant_name' => $unit->variant->displayName(),
                        'status' => $unit->status->value,
                        'condition_label' => $unit->condition->labelFa(),
                        'grade' => $unit->grade,
                        'warehouse_name' => $unit->warehouse?->name,
                        'acquired_from' => $parties[$unit->acquired_from_party_id ?? 0]->label ?? null,
                        'acquired_at' => $unit->acquired_at?->toIso8601String(),
                        'cost' => $showCost ? Money::toArray($unit->cost) : null,
                    ],
                    $units->items()
                ),
                'links' => $units->linkCollection()->toArray(),
                'total' => $units->total(),
            ],
            'filters' => [
                'q' => $term,
                'status' => $request->string('status')->value() ?: null,
                'warehouse_id' => $request->integer('warehouse_id') ?: null,
                'condition' => $request->string('condition')->value() ?: null,
                'hamta' => $request->string('hamta')->value() ?: null,
            ],
            'statuses' => $this->statusOptions(),
            'conditions' => $this->conditionOptions(),
            'warehouses' => $this->warehouseOptions(),
            'can' => ['view_cost' => $showCost],
        ]);
    }

    /**
     * One device's whole life.
     */
    public function show(
        Request $request,
        ProductUnit $unit,
        DocumentRegistry $documents,
        SubscriptionResolver $plan,
    ): Response {
        $this->authorize('view', $unit);

        $unit->load([
            'variant.product:id,name,type,brand_id',
            'variant.product.brand:id,name,name_fa',
            'warehouse.branch:id,name',
        ]);

        $user = $request->user();
        $showCost = $user?->can('inventory.view_cost') ?? false;
        $brand = $unit->variant->product->brand;

        $histories = ProductUnitHistory::query()
            ->with('actor:id,name')
            ->where('product_unit_id', $unit->getKey())
            ->orderBy('id')
            ->get();

        return Inertia::render('Inventory::Units/Show', [
            'unit' => [
                'id' => $unit->id,
                'imei1' => $unit->imei1,
                'imei2' => $unit->imei2,
                'serial' => $unit->serial,
                'product_name' => $unit->variant->product->name,
                'brand_name' => $brand === null ? null : ($brand->name_fa ?? $brand->name),
                'variant_name' => $unit->variant->displayName(),
                'status' => $unit->status->value,
                'condition' => $unit->condition->value,
                'condition_label' => $unit->condition->labelFa(),
                // A new sealed device has no cosmetic grade; the enum says so rather
                // than the screen guessing from an empty column.
                'uses_grade' => $unit->condition->usesGrade(),
                'grade' => $unit->grade,
                'warehouse_name' => $unit->warehouse?->name,
                'branch_name' => $unit->warehouse?->branch->name,
                'cost' => $showCost ? Money::toArray($unit->cost) : null,
                'acquired_from' => $unit->acquired_from_party_id === null
                    ? null
                    : $documents->describe(DocumentType::PARTY, $unit->acquired_from_party_id)?->toArray(),
                'acquired_at' => $unit->acquired_at?->toIso8601String(),
                'hamta_status' => $unit->hamta_status,
                'hamta_activation_id' => $unit->hamta_activation_id,
                'warranty_months' => $unit->warranty_months,
                'warranty_until' => $unit->warranty_until?->toIso8601String(),
                'notes' => $unit->notes,
            ],
            'timeline' => $this->timeline($histories, $documents),
            // The passport's doors: what this person may do next with this device. A
            // link to a screen that 403s is a worse welcome than no link (the dashboard's
            // quick actions make the same check for the same reason).
            'can' => [
                'view_cost' => $showCost,
                'sell' => $this->may($user, $plan, 'sales', 'sales.create'),
                'repair' => $this->may($user, $plan, 'repairs', 'repairs.create'),
                'label' => $this->may($user, $plan, 'catalog', 'catalog.view'),
            ],
        ]);
    }

    private function may(?\Illuminate\Contracts\Auth\Authenticatable $user, SubscriptionResolver $plan, string $module, string $permission): bool
    {
        return $user instanceof \App\Modules\Identity\Models\User
            && $plan->grants($module)
            && $user->can($permission);
    }

    /**
     * JSON lookup for `<UnitPicker/>`.
     *
     * Separate from `index()` on purpose: the picker is embedded in forms all over the
     * product and needs twelve rows and nothing else, while the register screen is a
     * paginated page with filters.
     */
    public function search(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ProductUnit::class);

        $term = trim($request->string('q')->value());
        $showCost = $request->user()?->can('inventory.view_cost') ?? false;

        $units = ProductUnit::query()
            ->with(['variant.product', 'warehouse'])
            ->when(
                $request->boolean('sellable', true),
                // The default, and the one the POS needs: a reserved or in-repair phone
                // is owned but not sellable, and offering it at the till is how the same
                // handset gets promised to two customers.
                fn ($query) => $query->where('status', UnitStatus::InStock->value),
                fn ($query) => $query->onHand(),
            )
            ->when(
                $request->filled('warehouse_id'),
                fn ($query) => $query->where('warehouse_id', $request->integer('warehouse_id'))
            )
            ->when($term !== '', fn ($query) => $query->where(
                fn ($q) => $q->matchingCode($term)
                    ->orWhereHas('variant.product', fn ($p) => $p->where('name', 'ilike', "%{$term}%"))
            ))
            ->orderByDesc('id')
            ->limit(self::SEARCH_LIMIT)
            ->get();

        return response()->json([
            'results' => $units->map(fn (ProductUnit $unit): array => [
                'id' => $unit->id,
                'imei1' => $unit->imei1,
                'imei2' => $unit->imei2,
                'serial' => $unit->serial,
                'product_name' => $unit->variant->product->name,
                'variant_name' => $unit->variant->displayName(),
                'status' => $unit->status->value,
                'condition_label' => $unit->condition->labelFa(),
                'grade' => $unit->grade,
                'warehouse_name' => $unit->warehouse?->name,
                // Withheld entirely rather than nulled for staff without
                // `inventory.view_cost` — Gate 1's Salesperson boundary.
                'cost' => $showCost ? Money::toArray($unit->cost) : null,
            ])->values()->all(),
        ]);
    }

    /**
     * The passport, oldest first, with every reference resolved to a Persian label.
     *
     * References are described in ONE batch per document type. A device that has been
     * transferred five times points at five transfers, and describing them one line at
     * a time is the N+1 that would make the longest passports the slowest.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, ProductUnitHistory>  $histories
     * @return list<array<string, mixed>>
     */
    private function timeline($histories, DocumentRegistry $documents): array
    {
        /** @var array<string, list<int|string>> $byType */
        $byType = [];

        foreach ($histories as $history) {
            if ($history->reference_type !== null && $history->reference_id !== null) {
                $byType[$history->reference_type][] = $history->reference_id;
            }
        }

        $described = [];

        foreach ($byType as $type => $ids) {
            $described[$type] = $documents->describeMany($type, $ids);
        }

        $timeline = [];

        foreach ($histories as $history) {
            $reference = null;

            if ($history->reference_type !== null && $history->reference_id !== null) {
                $reference = ($described[$history->reference_type][$history->reference_id] ?? null)?->toArray();
            }

            $timeline[] = [
                'id' => $history->id,
                'at' => $history->created_at->toIso8601String(),
                'from_status' => $history->from_status?->value,
                'to_status' => $history->to_status->value,
                // Null `from_status` is the acquisition line — the device did not exist
                // here before, so there is no transition to draw.
                'is_acquisition' => $history->from_status === null,
                'actor' => $history->actor?->name,
                'note' => $history->note,
                'reference' => $reference,
            ];
        }

        return $timeline;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function statusOptions(): array
    {
        return array_map(
            static fn (UnitStatus $status): array => ['value' => $status->value, 'label' => $status->labelFa()],
            UnitStatus::cases()
        );
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function conditionOptions(): array
    {
        return array_map(
            static fn (UnitCondition $condition): array => ['value' => $condition->value, 'label' => $condition->labelFa()],
            UnitCondition::cases()
        );
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
