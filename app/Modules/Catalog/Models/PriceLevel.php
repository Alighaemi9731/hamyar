<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tier of pricing: مصرف‌کننده, همکار, همکار ویژه.
 *
 * Iranian phone retail runs on reseller pricing — the همکار price is a real, everyday
 * concept, not an enterprise feature — so it is modelled from the start rather than
 * bolted on as a discount percentage later.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $code
 * @property string $name_fa
 * @property bool $is_default
 * @property int $position
 */
final class PriceLevel extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\PriceLevelFactory> */
    use HasFactory;

    public const CONSUMER = 'consumer';

    public const RESELLER = 'reseller';

    public const VIP = 'vip';

    protected $fillable = ['tenant_id', 'code', 'name_fa', 'is_default', 'position'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    /**
     * @return HasMany<ProductPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    /**
     * The three levels every shop starts with.
     *
     * @return list<array{code: string, name_fa: string, is_default: bool, position: int}>
     */
    public static function defaults(): array
    {
        return [
            ['code' => self::CONSUMER, 'name_fa' => 'مصرف‌کننده', 'is_default' => true, 'position' => 0],
            ['code' => self::RESELLER, 'name_fa' => 'همکار', 'is_default' => false, 'position' => 1],
            ['code' => self::VIP, 'name_fa' => 'همکار ویژه', 'is_default' => false, 'position' => 2],
        ];
    }
}
