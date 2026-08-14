<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Modules\Messaging\Enums\AutomationKey;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * What one automation says, for one shop.
 *
 * @property int $id
 * @property int $tenant_id
 * @property AutomationKey $automation_key
 * @property string $body
 * @property string|null $provider_template_id
 * @property bool $is_active
 */
final class MessageTemplate extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'automation_key', 'body', 'provider_template_id', 'is_active',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['is_active' => true];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'automation_key' => AutomationKey::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * Ready to send: switched on and pointing at a registered pattern.
     *
     * A template with no `provider_template_id` is skipped rather than sent as free text.
     * Free text to a number on the national do-not-disturb list is dropped by the carrier
     * without an error, so "sent" would be a lie the shop only discovers from a customer.
     */
    public function isSendable(): bool
    {
        return $this->is_active && $this->provider_template_id !== null && $this->provider_template_id !== '';
    }
}
