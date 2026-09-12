<?php

declare(strict_types=1);

namespace App\Modules\Settings\Providers;

use App\Modules\Settings\Policies\ShopSettingsPolicy;
use App\Modules\Settings\Services\TenantShopSettings;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Settings\ShopSettings;
use Illuminate\Support\Facades\Gate;

/**
 * Settings module.
 *
 * Spec: docs/specs/settings.md
 *
 * Migrations, views, translations and routes are picked up by convention — see
 * App\Support\Modules\ModuleServiceProvider. Register bindings and event listeners
 * here. Cross-module calls go through domain events or a public interface bound
 * below; never by reaching into another module's namespace (ADR 0003, enforced by
 * tests/Arch/ModuleBoundariesTest.php).
 */
final class SettingsServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        // The shared-kernel contract other modules depend on. Settings owns storing a
        // shop's preferences; Sales owns applying the rounding policy, and neither
        // imports the other (ADR 0003).
        $this->app->singleton(ShopSettings::class, TenantShopSettings::class);
    }

    public function boot(): void
    {
        parent::boot();

        /*
        | The policy hangs off the contract, not off a model: the values live in
        | `tenants.settings` and `tenants` is Platform's. See ShopSettingsPolicy for why
        | that is the right subject rather than a convenience — callers ask with the class
        | name, `$user->can('update', ShopSettings::class)`.
        */
        Gate::policy(ShopSettings::class, ShopSettingsPolicy::class);
    }
}
