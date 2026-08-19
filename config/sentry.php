<?php

declare(strict_types=1);

use App\Support\Observability\ScrubSensitiveData;

/*
|--------------------------------------------------------------------------
| Sentry — error reporting
|--------------------------------------------------------------------------
|
| Rewritten from the published default rather than kept whole. The vendor file is a
| catalogue of every switch the SDK has; this one is the handful of decisions that
| matter for a multi-tenant product, each with the reason beside it, so a future reader
| can tell a deliberate setting from an untouched default.
|
| ## Inert until it is given a DSN
|
| With `SENTRY_LARAVEL_DSN` empty the SDK captures nothing and sends nothing. That is
| the state of every developer machine, every CI run and every test, and it needs no
| conditional registration to achieve — which matters, because the alternative
| (`if (app()->isProduction())` around the service provider) is how a reporter ends up
| never having run anywhere before the day it is needed.
|
| ## What leaves this server is decided here, not by the vendor
|
| A crash reporter is the one component whose *job* is to take production data
| somewhere else. Three settings below carry the whole weight of that, and all three
| are hardcoded rather than env-driven on purpose: an environment variable is an
| invitation to flip one on during an incident, at exactly the moment nobody is
| thinking about disclosure.
|
| See App\Support\Observability\ScrubSensitiveData for the full reasoning.
|
*/

return [

    /*
    | Empty in every environment but production and staging, and that is deliberate.
    | See the header: absent DSN means the SDK is loaded, wired and silent.
    */
    'dsn' => env('SENTRY_LARAVEL_DSN'),

    /*
    | Which deployment an event came from. Falls back to APP_ENV so a staging box that
    | forgot the variable still separates itself from production rather than polluting
    | it — the failure mode is a mislabelled event, never a lost one.
    |
    | `?:`, not a second argument to env(): a key that is present and blank in a `.env`
    | is an empty string rather than absent, so the default would never be reached. The
    | same shape cost the Horizon dashboard its domain constraint — see config/horizon.php.
    */
    'environment' => env('SENTRY_ENVIRONMENT') ?: env('APP_ENV', 'production'),

    /*
    | The commit the box is running. Set by `bin/deploy` from the image tag, which is
    | the only place that knows it — an error report that cannot name the code it came
    | from sends you reading the wrong version of the file.
    */
    'release' => env('SENTRY_RELEASE'),

    /*
    | Errors: all of them. Fifty evaluating customers do not produce enough exceptions
    | for sampling to save anything, and a sampled error report is a support
    | conversation you cannot finish.
    */
    'sample_rate' => 1.0,

    /*
    | Performance traces: sampled, because these are produced by every request whether
    | or not anything is wrong.
    */
    'traces_sample_rate' => (float) env('SENTRY_TRACES_SAMPLE_RATE', 0.1),

    'profiles_sample_rate' => (float) env('SENTRY_PROFILES_SAMPLE_RATE', 0.0),

    /*
    |----------------------------------------------------------------------
    | The three that decide what leaves the building
    |----------------------------------------------------------------------
    */

    /*
    | No IP addresses, no cookie jar, no Authorization header, no authenticated user's
    | email. Hardcoded false: this is the switch whose default in most projects is
    | "turn it on, it helps debugging", and turning it on here ships fifty shops'
    | operators' identities to a third party to save a lookup.
    |
    | What replaces it is narrower and sufficient — ScrubSensitiveData tags every event
    | with the tenant id and slug, which is the question an incident actually asks
    | ("which shop?") without carrying anyone's personal data to answer it.
    */
    'send_default_pii' => false,

    'breadcrumbs' => [
        'logs' => true,
        'cache' => true,
        'livewire' => false,
        'queue_info' => true,
        'command_info' => true,
        'http_client_requests' => true,
        'notifications' => true,

        // The query *shape* is what makes a slow or wrong query legible.
        'sql_queries' => true,

        /*
        | The values are what make it a disclosure, and this is the single richest leak
        | available in this product. `select * from parties where national_id = ?`
        | carries a national id; the customer's phone number, a handset's IMEI and a
        | repair ticket's unlock code all reach the database the same way, as bindings,
        | after every model-level protection has already been satisfied.
        |
        | Encrypted columns do not help: the binding is the ciphertext on write, but the
        | *search* for a party by phone binds the plaintext.
        */
        'sql_bindings' => false,
    ],

    'tracing' => [
        'queue_job_transactions' => true,
        'queue_jobs' => true,
        'sql_queries' => true,
        'sql_origin' => true,
        'sql_origin_threshold_ms' => 100,
        'views' => true,
        'http_client_requests' => true,
        'cache' => true,
        'redis_commands' => false,
        'notifications' => true,
        'missing_routes' => false,
        'continue_after_response' => true,

        // Same reasoning as the breadcrumb above. Both doors, or neither.
        'sql_bindings' => false,
    ],

    /*
    | The last thing that runs before an event is transmitted. Scrubs the request body
    | and tags the shop. Applied to transactions as well as errors: a performance
    | transaction carries the same request payload, and a scrubber wired to only one of
    | the two reads in a diff as though it covers both.
    |
    | A **static callable**, not a closure and not an object. `config:cache` var_exports
    | this file; a closure cannot be exported and an object exports to a `__set_state()`
    | that does not exist, so either would turn caching config into a deploy-time fatal
    | on the one box nobody tries it on first. Two strings in an array export fine.
    */
    'before_send' => [ScrubSensitiveData::class, 'handle'],
    'before_send_transaction' => [ScrubSensitiveData::class, 'handle'],

    /*
    | The uptime probe hits /up every thirty seconds forever. Left in, it is the
    | busiest "transaction" on the platform and tells nobody anything.
    */
    'ignore_transactions' => [
        'GET /up',
        'GET /health',
    ],

    /*
    | Laravel's own log channel → Sentry. Off: the application already logs to the
    | stack channel, and duplicating it here doubles the quota for no new signal.
    | Exceptions arrive through the handler, not through the log.
    */
    'enable_logs' => false,

];
