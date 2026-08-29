<?php

declare(strict_types=1);

namespace App\Modules\Platform\Providers;

use App\Modules\Platform\Events\SubscriptionActivated;
use App\Modules\Platform\Listeners\ForgetResolvedSubscription;
use App\Modules\Platform\Models\Subscription;
use App\Modules\Platform\Policies\SubscriptionPolicy;
use App\Modules\Platform\Services\Payments\FakeGateway;
use App\Modules\Platform\Services\Payments\PaymentGateway;
use App\Modules\Platform\Services\Payments\ZarinpalGateway;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Platform module.
 *
 * Spec: docs/specs/platform.md
 *
 * Migrations, views, translations and routes are picked up by convention — see
 * App\Support\Modules\ModuleServiceProvider. Register bindings and event listeners
 * here. Cross-module calls go through domain events or a public interface bound
 * below; never by reaching into another module's namespace (ADR 0003, enforced by
 * tests/Arch/ModuleBoundariesTest.php).
 */
final class PlatformServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        /*
        | A singleton, and `bin/check-forgettable-singletons` now enforces it.
        |
        | This memoises the live subscription per tenant, and `forget()` is called after
        | every plan change — 84 call sites across the suite. Without a shared binding the
        | container handed back a fresh instance each time, so every one of those calls
        | cleared an empty cache while the instance the caller held kept answering. It did
        | not misbehave only because a fresh instance also starts empty; the memo simply
        | never worked, and a plan change mid-request would have been read stale.
        |
        | Safe to share because the cache is keyed by tenant id (`$this->cache[$tenantId]`).
        | A key that omitted the tenant would make this a cross-tenant leak the moment two
        | shops are served in one process.
        */
        $this->app->singleton(SubscriptionResolver::class);

        // One shared gateway instance per request: FakeGateway carries state a test sets
        // up (`willFail()`) and then asserts on, which a fresh instance per resolve would
        // silently discard.
        $this->app->singleton(PaymentGateway::class, function (Application $app): PaymentGateway {
            $driver = config()->string('services.payments.driver', 'zarinpal');

            if ($driver === 'fake') {
                return new FakeGateway;
            }

            $merchantId = config('services.payments.zarinpal.merchant_id');

            if (! is_string($merchantId) || $merchantId === '') {
                // Failing here beats failing at the gateway with an opaque error code
                // after the customer has already been redirected.
                throw new RuntimeException(
                    'ZARINPAL_MERCHANT_ID is not set. Set it, or use PAYMENT_DRIVER=fake for local work.'
                );
            }

            return new ZarinpalGateway(
                http: $app->make(Http::class),
                merchantId: $merchantId,
                sandbox: config()->boolean('services.payments.zarinpal.sandbox', true),
            );
        });
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(Subscription::class, SubscriptionPolicy::class);

        // The memo is per process and keyed by tenant; a plan change has to invalidate it
        // or the request that just took the money keeps answering from the old plan.
        Event::listen(SubscriptionActivated::class, ForgetResolvedSubscription::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                \App\Modules\Platform\Console\Commands\TenantSyncPermissionsCommand::class,
            ]);
        }
    }
}
