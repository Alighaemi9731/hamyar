<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Audit\AuditSubjects;
use App\Support\Audit\Redactor;
use App\Support\Documents\DocumentRegistry;
use App\Support\Quota\MetricRegistry;
use App\Support\Quota\NoQuota;
use App\Support\Quota\PeriodClock;
use App\Support\Quota\QuotaGuard;
use App\Support\Spreadsheet\CsvReader;
use App\Support\Spreadsheet\SpreadsheetReaders;
use App\Support\Spreadsheet\XlsxReader;
use App\Support\Timeline\TimelineRegistry;
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

        // Same shape, one level up: modules contribute what they know about a party and
        // the CRM customer page renders the union without importing any of them.
        $this->app->singleton(TimelineRegistry::class);

        /*
        | Fourth of the same shape, and the one with teeth: each module declares what it
        | meters, and Platform's guard prices it. Registration happens via
        | `afterResolving()` in each module's provider, so nothing may resolve this during
        | the register phase — metrics declared after the first build would be silently
        | absent, and `quota:audit` exists to catch exactly that.
        */
        $this->app->singleton(MetricRegistry::class);

        // One clock per process, constructed from config rather than reading it on every
        // call. The zone is the shop's wall clock (Asia/Tehran); when tenants get their
        // own it is constructed per tenant instead — which is why it takes the zone as a
        // constructor argument rather than reaching for config() inside.
        $this->app->singleton(
            PeriodClock::class,
            static fn (): PeriodClock => new PeriodClock(config()->string('app.display_timezone'))
        );

        /*
        | The quota guard's DEFAULT, and `bindIf` is load-bearing.
        |
        | Platform binds the real implementation with `singleton()` in its own provider,
        | which runs after this one. `bind` here instead of `bindIf` would be a coin toss
        | decided by provider discovery order, and the losing outcome is not a crash: it
        | is a product whose limits silently do nothing (CLAUDE.md, the `PartyExposure`
        | incident).
        */
        $this->app->bindIf(QuotaGuard::class, NoQuota::class);

        // Third of the same shape: the audit-log viewer needs a Persian name and a
        // URL-safe key for every kind of thing the log can be about, and getting that
        // from a map here would point App\Support at Catalog, CRM and Repairs. Modules
        // declare their own subjects instead, beside the models they audit.
        $this->app->singleton(AuditSubjects::class);

        // Shared for its cache, which is keyed by class name and holds nothing
        // tenant-specific: what counts as a secret on a model is a property of the
        // class, identical for every shop. Rebuilding it per row would instantiate a
        // model per activity entry rendered.
        $this->app->singleton(Redactor::class);

        // Customer lists arrive as whatever the sender's Excel exported. Both readers
        // register here and the import service learns neither format exists — it asks
        // the registry for whatever opens the file it was handed.
        $this->app->singleton(SpreadsheetReaders::class, function (): SpreadsheetReaders {
            $readers = new SpreadsheetReaders;
            $readers->register(new CsvReader);
            $readers->register(new XlsxReader);

            return $readers;
        });
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
