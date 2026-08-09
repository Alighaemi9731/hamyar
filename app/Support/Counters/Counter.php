<?php

declare(strict_types=1);

namespace App\Support\Counters;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One sequential counter for one tenant.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $key
 * @property int $value
 * @property string|null $period
 */
final class Counter extends Model
{
    use BelongsToTenant;

    public const SUBSCRIPTION_INVOICE = 'subscription_invoice';

    public const SALES_INVOICE = 'sales_invoice';

    public const REPAIR_TICKET = 'repair_ticket';

    protected $fillable = ['tenant_id', 'key', 'value', 'period'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['value' => 'integer'];
    }
}
