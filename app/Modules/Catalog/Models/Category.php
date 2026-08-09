<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A node in the shop's product tree.
 *
 * Adjacency list rather than nested sets: real shop trees are two or three levels
 * (گوشی موبایل › اپل › آیفون ۱۵) and nested sets would be machinery for a depth nobody
 * reaches, at the cost of a rebuild on every insert.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $parent_id
 * @property string $name
 * @property string $slug
 * @property int $position
 */
final class Category extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\CategoryFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = ['tenant_id', 'parent_id', 'name', 'slug', 'position'];

    /**
     * @return BelongsTo<Category, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id')->orderBy('position');
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
