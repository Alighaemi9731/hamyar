<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\CRM\Models\Party;
use App\Modules\Inventory\Models\Branch;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PurchaseInvoice>
 */
final class PurchaseInvoiceFactory extends Factory
{
    protected $model = PurchaseInvoice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $branch = Branch::factory();

        return [
            'branch_id' => $branch,
            'warehouse_id' => Warehouse::factory()->state(fn (array $attributes): array => [
                // The warehouse must sit in the same branch, or a test builds a shipment
                // received into a location the branch does not own.
                'branch_id' => $attributes['branch_id'] ?? Branch::factory(),
            ]),
            'party_id' => Party::factory()->supplier(),
            'number' => 'PUR-'.Str::upper(Str::random(6)),
            'status' => PurchaseInvoice::STATUS_DRAFT,
            'issued_at' => now(),
        ];
    }

    public function received(): self
    {
        return $this->state(fn (): array => [
            'status' => PurchaseInvoice::STATUS_RECEIVED,
            'received_at' => now(),
        ]);
    }

    /**
     * Opening stock the shop already owned: no supplier to owe.
     */
    public function withoutSupplier(): self
    {
        return $this->state(fn (): array => ['party_id' => null]);
    }
}
