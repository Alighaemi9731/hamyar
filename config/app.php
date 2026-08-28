<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Golden rule 5: Hamyar STORES every timestamp in UTC. This value must stay
    | "UTC" — changing it silently rewrites the meaning of every stored date.
    |
    | The shop's wall-clock timezone lives in `display_timezone` below and is applied
    | only at the rendering boundary (App\Support\Jalali). That ordering matters:
    | converting a UTC instant straight to Jalali reports the wrong *day* for
    | anything after 20:30 Tehran time, which is exactly when a shop closes its till.
    |
    */

    'timezone' => env('APP_TIMEZONE', 'UTC'),

    /*
    |--------------------------------------------------------------------------
    | Display Timezone
    |--------------------------------------------------------------------------
    |
    | Wall-clock timezone used when rendering dates for humans. Never stored.
    |
    */

    'display_timezone' => env('APP_DISPLAY_TIMEZONE', 'Asia/Tehran'),

    /*
    |--------------------------------------------------------------------------
    | Currency Display Unit
    |--------------------------------------------------------------------------
    |
    | Default money unit shown to users: "rial" or "toman". Iranian shops quote in
    | toman (1 toman = 10 rial) but golden rule 2 keeps storage in integer rial
    | regardless. Individual tenants override this in their settings.
    |
    */

    'currency_display' => env('APP_CURRENCY_DISPLAY', 'toman'),

    /*
    |--------------------------------------------------------------------------
    | Tenant Domain
    |--------------------------------------------------------------------------
    |
    | Bare domain that tenant subdomains hang off: <shop>.app.localhost. Used by
    | the tenant-resolution middleware (Phase 1) and by route domain constraints.
    |
    */

    'domain' => env('APP_DOMAIN', 'app.localhost'),

    /*
    |--------------------------------------------------------------------------
    | Health-check Secret
    |--------------------------------------------------------------------------
    |
    | Unlocks the detailed body of `/health` for a caller that presents it in the
    | `X-Health-Secret` header. Without it the endpoint still answers — with a bare
    | ok/unhealthy and the right status code, which is all an uptime probe needs.
    |
    | Unset means **nobody** sees details, including on a box where somebody forgot to
    | set it. The other default (no secret, so show everyone) publishes internal
    | hostnames and driver-level failure messages to the open internet, and does it
    | most loudly at the moment something is already broken.
    |
    */

    'health_secret' => env('HEALTH_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Release identity
    |--------------------------------------------------------------------------
    |
    | Two answers to one question — "which build am I looking at?" — and the reason
    | there are two is that they fail independently.
    |
    | `version` is the semantic version, read from the `VERSION` file at the repository
    | root so there is exactly one place to bump (docs/VERSIONING.md). No mirror in a
    | PHP constant, because a mirror is a thing that drifts. `config:cache` runs on the
    | box after the tree is synced, so the cached value is the synced file's.
    |
    | `release` is the exact image tag, baked into the image by
    | `docker/app/Dockerfile.prod` from its `APP_RELEASE` build argument — the image is
    | the only thing that actually knows which commit it contains. **Never add
    | `APP_RELEASE` to `.env.production`:** `env_file` overrides the image's own ENV, so
    | an empty line there silently replaces the baked value with nothing. That is the
    | same trap the `SENTRY_RELEASE` note in `.env.production.example` describes, and it
    | is invisible — the field simply reads `null`, which looks like a build that
    | predates this feature rather than like a misconfiguration.
    |
    | Surfaced through `/health`: the version publicly, the image tag only to a caller
    | holding `HEALTH_SECRET`. Both exist because a finished change sitting on a branch
    | and a deployed change look identical from the outside, and that cost this project
    | a day.
    |
    */

    'version' => trim((string) @file_get_contents(base_path('VERSION'))) ?: 'dev',

    'release' => env('APP_RELEASE'),

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'fa'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'fa_IR'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
