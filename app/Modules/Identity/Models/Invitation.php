<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A pending invitation to join a shop's staff.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string|null $email
 * @property string $mobile
 * @property string $role
 * @property \Carbon\CarbonImmutable $expires_at
 * @property \Carbon\CarbonImmutable|null $accepted_at
 * @property \Carbon\CarbonImmutable|null $revoked_at
 */
final class Invitation extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'invited_by_id',
        'name',
        'mobile',
        'email',
        'role',
        'token_hash',
        'expires_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_id');
    }

    /**
     * Mint a token: the plaintext goes in the link, only the hash is stored.
     *
     * @return array{token: string, hash: string}
     */
    public static function mintToken(): array
    {
        $token = Str::random(48);

        return ['token' => $token, 'hash' => hash('sha256', $token)];
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null
            && $this->revoked_at === null
            && $this->expires_at->isFuture();
    }

    public function status(): string
    {
        return match (true) {
            $this->accepted_at !== null => 'accepted',
            $this->revoked_at !== null => 'revoked',
            $this->expires_at->isPast() => 'expired',
            default => 'pending',
        };
    }
}
