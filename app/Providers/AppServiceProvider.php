<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureModels();
        $this->configureDates();
        $this->configurePasswords();
        $this->configureUrls();
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
        Password::defaults(fn (): Password => Password::min(8)->uncompromised());
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
