<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\PriceLevel;
use App\Modules\Identity\Models\User;
use App\Modules\Storefront\Http\Requests\PriceListLinkRequest;
use App\Modules\Storefront\Http\Requests\StorefrontSettingsRequest;
use App\Modules\Storefront\Models\PriceListLink;
use App\Modules\Storefront\Models\StorefrontSetting;
use App\Modules\Storefront\Services\PriceListAccess;
use App\Support\Jalali;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The shop's side: settings, and the links they hand out.
 *
 * ## The token is shown exactly once, in a flash message
 *
 * It is stored hashed (see {@see PriceListAccess}), so there is no second chance to read it
 * — and that is the point rather than an inconvenience. The screen says so plainly at the
 * moment of creation, because a shopkeeper who navigates away and comes back looking for the
 * URL needs to understand immediately that minting another one is the answer.
 */
final class StorefrontAdminController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorise($request, 'settings.view');

        $settings = StorefrontSetting::query()->first();

        $links = PriceListLink::query()
            ->with('priceLevel:id,name_fa')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return Inertia::render('Storefront::Storefront/Index', [
            'settings' => $settings === null ? null : [
                'is_enabled' => $settings->is_enabled,
                'slug' => $settings->slug,
                'display_name' => $settings->display_name,
                'about' => $settings->about,
                'address' => $settings->address,
                'phone' => $settings->phone,
                'whatsapp' => $settings->whatsapp,
                'working_hours' => $settings->working_hours,
                'shows_out_of_stock' => $settings->shows_out_of_stock,
            ],
            'public_url' => $settings?->is_enabled === true ? url('/shop') : null,
            'price_levels' => PriceLevel::query()
                ->orderBy('position')
                ->get(['id', 'name_fa'])
                ->map(fn (PriceLevel $level): array => ['id' => $level->getKey(), 'name' => $level->name_fa])
                ->values()
                ->all(),
            'links' => $links->map(fn (PriceListLink $link): array => [
                'id' => $link->getKey(),
                'label' => $link->label,
                'level' => $link->priceLevel?->name_fa,
                'expires_at' => Jalali::format($link->expires_at),
                'is_expired' => $link->isExpired(),
                'is_revoked' => $link->isRevoked(),
                'has_password' => $link->needsPassword(),
                'view_count' => $link->view_count,
                'last_viewed_at' => Jalali::format($link->last_viewed_at),
            ])->values()->all(),
            'can_manage' => $request->user() instanceof User && $request->user()->can('settings.update'),
        ]);
    }

    public function update(StorefrontSettingsRequest $request): RedirectResponse
    {
        $this->authorise($request, 'settings.update');

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        StorefrontSetting::query()->updateOrCreate([], [
            'is_enabled' => $request->boolean('is_enabled'),
            'slug' => $data['slug'] ?? null,
            'display_name' => $data['display_name'] ?? null,
            'about' => $data['about'] ?? null,
            'address' => $data['address'] ?? null,
            // Normalised on the way in, like every number in this product: a WhatsApp link
            // built from «۰۹۱۲ ۱۲۳ ۴۵۶۷» has to open a chat, not an error.
            'phone' => $this->normalise($data['phone'] ?? null),
            'whatsapp' => $this->normalise($data['whatsapp'] ?? null),
            'working_hours' => $data['working_hours'] ?? null,
            'shows_out_of_stock' => $request->boolean('shows_out_of_stock'),
        ]);

        return back()->with('success', 'تنظیمات فروشگاه ذخیره شد.');
    }

    public function store(PriceListLinkRequest $request, PriceListAccess $access): RedirectResponse
    {
        $this->authorise($request, 'settings.update');

        $days = $request->integer('days') ?: PriceListAccess::DEFAULT_DAYS;

        $minted = $access->mint(
            priceLevelId: $request->integer('price_level_id'),
            password: $request->string('password')->value() ?: null,
            expiresAt: CarbonImmutable::now()->addDays($days),
            label: $request->string('label')->value() ?: null,
            actorId: $request->user() instanceof User ? idOfModel($request->user()) : null,
        );

        /*
        | Flashed once and never retrievable. The token is stored hashed, so this really is
        | the only moment it exists in readable form — the screen says so beside it.
        */
        return back()->with('minted_link', url('/p/'.$minted['token']));
    }

    /**
     * Revoke, effective immediately — the spec's word.
     *
     * Nothing caches a link's state, so the next request on that URL is already closed.
     * Soft-closed rather than deleted, because the view log is the thing a shop reads when
     * they suspect a list has travelled, and deleting the row would take that with it.
     */
    public function revoke(Request $request, PriceListLink $link): RedirectResponse
    {
        $this->authorise($request, 'settings.update');

        $link->forceFill(['revoked_at' => CarbonImmutable::now()])->save();

        return back()->with('success', 'لینک باطل شد.');
    }

    private function normalise(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return PhoneNumber::normalise($value) ?? trim($value);
    }

    private function authorise(Request $request, string $permission): void
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->can($permission), 403);
    }
}
