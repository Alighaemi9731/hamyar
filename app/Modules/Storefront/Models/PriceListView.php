<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Models;

use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One opening of a price list.
 *
 * The row that lets a shop see a link travelling further than they sent it: a list
 * forwarded to a competitor looks identical to one used by its recipient, except here.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $price_list_link_id
 * @property string|null $ip
 * @property string|null $user_agent
 * @property CarbonImmutable $viewed_at
 */
final class PriceListView extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = ['tenant_id', 'price_list_link_id', 'ip', 'user_agent', 'viewed_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['viewed_at' => 'immutable_datetime'];
    }
}
