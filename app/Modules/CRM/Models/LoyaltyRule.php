<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * How a shop turns money spent into points.
 *
 * One active rule per shop, enforced by a partial unique index: the earn calculation
 * has to have exactly one answer, and "whichever row the query returned first" is not
 * one. Changing the scheme deactivates the old rule rather than editing it, so points
 * already earned stay explainable by the rule that granted them.
 *
 * A stub, deliberately. Campaigns, tiers and per-category multipliers are Phase 8
 * (Messaging owns campaigns); what exists here is the one rule a shop actually asks
 * for on day one — "هر صد هزار تومان، یک امتیاز".
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property int $rial_per_point
 * @property int|null $expires_after_months null = never expires
 * @property bool $is_active
 */
final class LoyaltyRule extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'name', 'rial_per_point', 'expires_after_months', 'is_active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rial_per_point' => 'integer',
            'expires_after_months' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Points earned by spending this much.
     *
     * `intdiv` truncates, so 149,000 rial at 100,000-per-point earns one point, not
     * one-and-a-half rounded up to two. A scheme that rounds in the customer's favour
     * is a scheme whose cost the shop cannot predict.
     */
    public function pointsFor(int $rial): int
    {
        if ($rial <= 0 || $this->rial_per_point <= 0) {
            return 0;
        }

        return intdiv($rial, $this->rial_per_point);
    }
}
