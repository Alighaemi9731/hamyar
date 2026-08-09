<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Documents\DocumentRegistry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Shared kernel, not a module: modules register describers for their own
        // documents here, and screens that must name another module's document read
        // from it — so neither side imports the other (ADR 0003).
        $this->app->singleton(DocumentRegistry::class);
    }

    public function boot(): void
    {
        $this->configureFactories();
        $this->configureModels();
        $this->configureDates();
        $this->configurePasswords();
        $this->configureUrls();
    }

    /**
     * Teach Laravel where a module model's factory lives.
     *
     * The default guess strips the `App\Models\` prefix, so our modular layout
     * (ADR 0003) produces nonsense like
     * `Database\Factories\Modules\Platform\Models\TenantFactory`.
     *
     * A module-namespaced factory wins when present, so a future collision between,
     * say, Sales\Payment and Platform\Payment has somewhere to go; otherwise the flat
     * `Database\Factories\<Model>Factory` is used.
     */
    private function configureFactories(): void
    {
        Factory::guessFactoryNamesUsing(
            /**
             * @param  class-string<Model>  $model
             * @return class-string<Factory<Model>>
             */
            function (string $model): string {
                $basename = class_basename($model);

                if (str_starts_with($model, 'App\\Modules\\')) {
                    $module = Str::before(Str::after($model, 'App\\Modules\\'), '\\');
                    $namespaced = "Database\\Factories\\{$module}\\{$basename}Factory";

                    if (class_exists($namespaced)) {
                        /** @var class-string<Factory<Model>> $namespaced */
                        return $namespaced;
                    }
                }

                /** @var class-string<Factory<Model>> $flat */
                $flat = "Database\\Factories\\{$basename}Factory";

                return $flat;
            }
        );
    }

    /**
     * Strictness that turns silent data bugs into loud failures.
     *
     * All three are disabled in production: an N+1 or a stray attribute should be
     * fixed in development and CI, not turned into a 500 for a shop mid-sale.
     */
    private function configureModels(): void
    {
        $strict = ! $this->app->isProduction();

        // Lazy loading a relation inside a report loop is the single easiest way to
        // turn a 200ms page into a 20s one, and it never shows up locally on 3 rows.
        Model::preventLazyLoading($strict);

        // Mass-assigning a key that isn't fillable normally vanishes without trace.
        // In a money/stock domain that means a discount or a cost silently not saving.
        Model::preventSilentlyDiscardingAttributes($strict);

        // Accessing an attribute that wasn't selected returns null by default, which
        // in a ledger sum reads as zero — a wrong number rather than an error.
        Model::preventAccessingMissingAttributes($strict);

        Model::shouldBeStrict($strict);
    }

    /**
     * Use CarbonImmutable everywhere.
     *
     * Mutable dates are a real hazard in this domain: `$dueDate->addMonths(1)` inside
     * an installment-schedule loop mutates the shared instance and silently produces
     * a compounding schedule instead of a monthly one.
     */
    private function configureDates(): void
    {
        Date::use(\Carbon\CarbonImmutable::class);

        // Storage is UTC (golden rule 5); rendering goes through App\Support\Jalali.
        Carbon::setLocale(config()->string('app.locale', 'fa'));
    }

    private function configurePasswords(): void
    {
        Password::defaults(function (): Password {
            $rule = Password::min(8);

            // `uncompromised()` calls the Have I Been Pwned API. That is exactly right
            // in production and unacceptable in a test: it would make the suite depend
            // on an external service, fail offline, and leak a hash prefix per run
            // (docs/testing.md — "no network from a test").
            return $this->app->runningUnitTests() ? $rule : $rule->uncompromised();
        });
    }

    private function configureUrls(): void
    {
        // Public tracking links and reseller price lists are signed URLs handed to
        // people outside the shop; they must be https once we are off localhost.
        if ($this->app->environment('production', 'staging')) {
            URL::forceScheme('https');
        }
    }
}
