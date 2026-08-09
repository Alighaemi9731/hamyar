<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A label a shop puts on parties: «بدحساب», «مشتری ویژه», «تعمیرکار».
 *
 * Free-form on purpose. Every shop segments its customers differently and a fixed list
 * would be wrong for all of them.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string|null $colour
 */
final class PartyTag extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'name', 'colour'];

    /**
     * @return BelongsToMany<Party, $this>
     */
    public function parties(): BelongsToMany
    {
        return $this->belongsToMany(Party::class, 'party_tag_assignments')
            ->withPivotValue('tenant_id', $this->tenant_id)
            ->withTimestamps();
    }
}
