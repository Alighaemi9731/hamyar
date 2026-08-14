<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A number that has asked to stop hearing from this shop.
 *
 * Keyed on the phone rather than the party, because that is what the person asking has.
 * The same number may sit on three party rows after a spreadsheet import, and suppressing
 * only the one somebody happened to link would keep the messages coming.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $phone canonical +98 form
 * @property string|null $reason
 * @property CarbonImmutable $opted_out_at
 */
final class MessageOptOut extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'phone', 'reason', 'opted_out_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['opted_out_at' => 'immutable_datetime'];
    }
}
