<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Modules\Catalog\Models\PriceLevel;
use App\Modules\CRM\Enums\PartyKind;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Anyone with a balance: customer, supplier, or همکار.
 *
 * The balance itself is NOT here — it is a SUM over `ledger_entries`
 * ({@see \App\Modules\CRM\Services\LedgerService}). `opening_balance` is the one stored
 * figure, and it is a starting point carried in from whatever the shop used before, not
 * a running total.
 *
 * @property int $id
 * @property int $tenant_id
 * @property PartyKind $kind
 * @property string $name
 * @property string|null $company_name
 * @property string|null $national_id
 * @property string|null $economic_code
 * @property int|null $price_level_id
 * @property int|null $credit_limit
 * @property int $opening_balance
 * @property CarbonImmutable|null $birthday
 * @property bool $is_active
 * @property string|null $notes
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class Party extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\PartyFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'kind', 'name', 'company_name', 'national_id', 'economic_code',
        'price_level_id', 'credit_limit', 'opening_balance', 'birthday', 'is_active', 'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => PartyKind::class,
            'credit_limit' => 'integer',
            'opening_balance' => 'integer',
            'birthday' => 'immutable_date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<PartyContact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(PartyContact::class);
    }

    /**
     * @return HasMany<PartyAddress, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(PartyAddress::class);
    }

    /**
     * Tags, with `tenant_id` filled on the pivot when one is attached.
     *
     * The id comes from the tenant **context**, not from `$this->tenant_id`. Eager
     * loading builds the relation on a fresh, attribute-less instance
     * (`Relation::noConstraints`), so reading it off the model produced null and
     * `withPivotValue` threw — which meant `with('tags')` blew up while a lazy
     * `$party->tags` worked, and nothing eager-loaded it until the customer page did.
     *
     * @return BelongsToMany<PartyTag, $this>
     */
    public function tags(): BelongsToMany
    {
        $relation = $this->belongsToMany(PartyTag::class, 'party_tag_assignments')->withTimestamps();

        $tenantId = app(TenantContext::class)->id() ?? $this->tenant_id;

        // Reads are confined by RLS and the pivot's own scope regardless; this only
        // decides what an attach() writes.
        return $tenantId === null ? $relation : $relation->withPivotValue('tenant_id', $tenantId);
    }

    /**
     * @return HasMany<LedgerEntry, $this>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /**
     * Dated notes, newest first — the conversation, not the description.
     *
     * @return HasMany<PartyNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(PartyNote::class)->latest('created_at');
    }

    /**
     * @return HasMany<PartyFollowUp, $this>
     */
    public function followUps(): HasMany
    {
        return $this->hasMany(PartyFollowUp::class);
    }

    /**
     * @return HasMany<LoyaltyEntry, $this>
     */
    public function loyaltyEntries(): HasMany
    {
        return $this->hasMany(LoyaltyEntry::class);
    }

    /**
     * @return BelongsTo<PriceLevel, $this>
     */
    public function priceLevel(): BelongsTo
    {
        return $this->belongsTo(PriceLevel::class, 'price_level_id');
    }

    /**
     * The number the counter actually dials.
     */
    public function primaryMobile(): ?string
    {
        $contact = $this->contacts
            ->where('type', PartyContact::TYPE_MOBILE)
            ->sortByDesc('is_primary')
            ->first();

        return $contact?->value;
    }

    /**
     * @param  Builder<Party>  $query
     * @return Builder<Party>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        // Name, company, national id and any contact value in one box — the counter
        // does not know which field the customer will give them.
        return $query->where(function (Builder $q) use ($term): void {
            $q->where('name', 'ilike', "%{$term}%")
                ->orWhere('company_name', 'ilike', "%{$term}%")
                ->orWhere('national_id', $term)
                ->orWhereHas('contacts', fn (Builder $c) => $c->where('value', 'ilike', "%{$term}%"));
        });
    }
}
