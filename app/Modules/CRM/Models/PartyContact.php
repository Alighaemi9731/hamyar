<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Support\Digits;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One way to reach a party. Several per party; one primary per type.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $party_id
 * @property string $type
 * @property string $value
 * @property string|null $label
 * @property bool $is_primary
 */
final class PartyContact extends Model
{
    use BelongsToTenant;

    public const TYPE_MOBILE = 'mobile';

    public const TYPE_PHONE = 'phone';

    public const TYPE_EMAIL = 'email';

    protected $fillable = ['tenant_id', 'party_id', 'type', 'value', 'label', 'is_primary'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    protected static function booted(): void
    {
        self::saving(function (PartyContact $contact): void {
            if ($contact->type === self::TYPE_EMAIL) {
                return;
            }

            // Phone numbers are searched constantly at the counter, and a number saved
            // with Persian digits would never match one typed with Latin ones. Separators
            // go too, so 0912-111-2233 and 09121112233 are the same customer.
            $latin = Digits::toLatin($contact->value);
            $contact->value = preg_replace('/[^\d+]/', '', $latin) ?? $contact->value;
        });
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }
}
