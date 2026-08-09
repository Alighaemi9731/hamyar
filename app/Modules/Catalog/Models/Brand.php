<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A manufacturer.
 *
 * Two names on purpose: the Latin one matches supplier invoices and is what people type
 * into search, the Persian one is what the UI shows. Searching for "samsung" must find
 * سامسونگ.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string|null $name_fa
 * @property int $position
 */
final class Brand extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\BrandFactory> */
    use HasFactory;
    use SoftDeletes;

    protected $fillable = ['tenant_id', 'name', 'name_fa', 'position'];

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function displayName(): string
    {
        return $this->name_fa ?? $this->name;
    }
}
