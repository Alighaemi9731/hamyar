<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One shop's public face.
 *
 * @property int $id
 * @property int $tenant_id
 * @property bool $is_enabled
 * @property string|null $slug
 * @property string|null $display_name
 * @property string|null $about
 * @property string|null $address
 * @property string|null $phone
 * @property string|null $whatsapp
 * @property string|null $working_hours
 * @property array<int, int>|null $categories
 * @property bool $shows_out_of_stock
 */
final class StorefrontSetting extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'is_enabled', 'slug', 'display_name', 'about', 'address',
        'phone', 'whatsapp', 'working_hours', 'categories', 'shows_out_of_stock',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'shows_out_of_stock' => 'boolean',
            'categories' => 'array',
        ];
    }
}
