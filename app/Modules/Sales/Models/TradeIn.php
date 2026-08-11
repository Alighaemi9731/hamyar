<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Modules\CRM\Models\Party;
use App\Modules\Inventory\Models\ProductUnit;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * معاوضه — the customer's old handset, taken in part-payment.
 *
 * The shop buys a device and sells one in the same conversation, and the customer walks
 * out having paid the difference. That makes this both a purchase (it creates a used
 * `product_unit` with its own cost and passport) and a line against the sale.
 *
 * `hamta_ack` is a boolean somebody had to tick, deliberately. The shop carries real
 * liability when a stolen handset is traded in, and "the salesperson confirmed they
 * walked the customer through the ownership transfer" is a different claim from "the
 * transfer happened" — this records the first, which is the one the shop can honestly
 * make at the counter.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $sales_invoice_id
 * @property int|null $party_id
 * @property string $device_name
 * @property string|null $imei1
 * @property string $condition
 * @property string|null $grade
 * @property int $agreed_price
 * @property int|null $product_unit_id
 * @property int|null $id_scan_media_id
 * @property bool $hamta_ack
 */
final class TradeIn extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'sales_invoice_id', 'party_id', 'device_name', 'imei1',
        'condition', 'grade', 'agreed_price', 'product_unit_id',
        'id_scan_media_id', 'hamta_ack',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['agreed_price' => 'integer', 'hamta_ack' => 'boolean'];
    }

    /**
     * @return BelongsTo<SalesInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    /**
     * @return BelongsTo<ProductUnit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class, 'product_unit_id');
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }
}
