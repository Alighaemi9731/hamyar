<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a party is. Used for delivery and printed on an invoice when set.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $party_id
 * @property string|null $label
 * @property string|null $province
 * @property string|null $city
 * @property string|null $line
 * @property string|null $postal_code
 * @property bool $is_primary
 */
final class PartyAddress extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'party_id', 'label', 'province', 'city', 'line', 'postal_code', 'is_primary',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }
}
