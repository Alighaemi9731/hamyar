<?php

declare(strict_types=1);

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Name
    |--------------------------------------------------------------------------
    |
    | This name appears in notifications and in the Horizon UI. Unique names
    | can be useful while running multiple instances of Horizon within an
    | application, allowing you to identify the Horizon you're viewing.
    |
    */

    'name' => env('HORIZON_NAME'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    /*
    | The central domain, never a tenant subdomain.
    |
    | Defence in depth rather than the control itself — `HorizonServiceProvider`'s gate
    | is what actually decides — but a dashboard that simply does not answer on
    | `<shop>.<apex>` is one fewer surface to reason about.
    |
    | Read from the environment because config files load before `config()` is
    | populated; it is still the one `APP_DOMAIN` everything else resolves from, so
    | changing the apex stays a config change (golden rule 1b).
    |
    | **`?:` and not `env('HORIZON_DOMAIN', env('APP_DOMAIN'))`.** A key that is *present
    | and blank* in a `.env` file — `HORIZON_DOMAIN=`, which is how this one is
    | documented — is an empty string, not absent, so the second argument is never
    | reached. The result is `domain => ''`, which Horizon reads as "no constraint" and
    | serves the dashboard on **every tenant subdomain**.
    |
    | Caught by `HorizonAccessTest` in CI and nowhere else: CI builds its environment
    | with `cp .env.example .env`, so it is the only place that actually exercises the
    | documented file. Locally the key is usually missing and the default works, which is
    | the worst version of this bug — it behaves correctly for whoever wrote it.
    */
    'domain' => env('HORIZON_DOMAIN') ?: env('APP_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | This is the name of the Redis connection where Horizon will store the
    | meta information required for it to function. It includes the list
    | of supervisors, failed jobs, job metrics, and other information.
    |
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be used when storing all Horizon data in Redis. You
    | may modify the prefix when you are running multiple installations
    | of Horizon on the same server so that they don't have problems.
    |
    */

    // `(string)` around env(): the published default passes env() straight into
    // Str::slug(), which is bool|string at Larastan level 8 and fails the build.
    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug((string) env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto each Horizon route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure when the LongWaitDetected event
    | will be fired. Every connection / queue combination may have its
    | own, unique threshold (in seconds) before this event is fired.
    |
    */

    'waits' => [
        'redis:default' => 60,

        // The roadmap's own alert threshold, and the reason `sms` is a separate
        // supervisor: a customer waiting for a repair-ready text notices a minute.
        'redis:sms' => 60,

        // Moadian is a government endpoint with government latency, and a submission
        // is not time-critical to anybody standing at a counter. Alerting at a minute
        // here would train everyone to ignore the alert.
        'redis:moadian' => 900,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Here you can configure for how long (in minutes) you desire Horizon to
    | persist the recent and failed jobs. Typically, recent jobs are kept
    | for one hour while all failed jobs are stored for an entire week.
    |
    */

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        // A fortnight rather than a week. A failed Moadian submission is a tax
        // obligation somebody has to resolve, and it is entirely normal for that to
        // surface after a week — at which point the payload is the only record of what
        // was attempted.
        'recent_failed' => 20160,
        'failed' => 20160,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    |
    | Silencing a job will instruct Horizon to not place the job in the list
    | of completed jobs within the Horizon dashboard. This setting may be
    | used to fully remove any noisy jobs from the completed jobs list.
    |
    */

    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    'silenced_tags' => [
        // 'notifications',
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Here you can configure how many snapshots should be kept to display in
    | the metrics graph. This will get used in combination with Horizon's
    | `horizon:snapshot` schedule to define how long to retain metrics.
    |
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Horizon's "terminate" command will not
    | wait on all of the workers to terminate unless the --wait option
    | is provided. Fast termination can shorten deployment delay by
    | allowing a new instance of Horizon to start while the last
    | instance will continue to terminate each of its workers.
    |
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | This value describes the maximum amount of memory the Horizon master
    | supervisor may consume before it is terminated and restarted. For
    | configuring these limits on your workers, see the next section.
    |
    */

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define the queue worker settings used by your application
    | in all environments. These supervisors and settings handle all your
    | queued jobs and will be provisioned by Horizon during deployment.
    |
    */

    'defaults' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Supervisors — one per queue, deliberately
    |--------------------------------------------------------------------------
    |
    | The tempting shape is a single supervisor over `['default', 'sms', 'moadian']`,
    | which is simpler and wrong here. A shared pool means one slow queue starves the
    | others: Moadian is a government endpoint that can sit for thirty seconds a call,
    | and a backlog of tax submissions would occupy every worker the platform has while
    | fifty shops' repair-ready texts queue behind it.
    |
    | Separate supervisors also make the timeouts honest. `default` carries work that
    | should be quick and is a bug if it is not; `moadian` carries work that is
    | legitimately slow. One timeout cannot be right for both — it is either too short
    | to submit an invoice or too long to notice a wedged job.
    |
    */
    'environments' => [
        'production' => [
            'supervisor-default' => [
                'connection' => 'redis',
                'queue' => ['default'],
                'balance' => 'auto',
                'minProcesses' => 1,
                'maxProcesses' => 10,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
                'memory' => 256,
                'tries' => 3,
                'timeout' => 60,
            ],

            'supervisor-sms' => [
                'connection' => 'redis',
                'queue' => ['sms'],
                'balance' => 'auto',
                'minProcesses' => 1,
                'maxProcesses' => 5,
                'memory' => 128,
                // Iranian SMS gateways have outages, and a text is worth retrying
                // harder than most work: the alternative is a customer never told
                // their phone is ready.
                'tries' => 5,
                'backoff' => [10, 60, 300],
                'timeout' => 30,
            ],

            'supervisor-moadian' => [
                'connection' => 'redis',
                'queue' => ['moadian'],
                'balance' => 'auto',
                'minProcesses' => 1,
                'maxProcesses' => 3,
                'memory' => 256,
                'tries' => 5,
                'backoff' => [60, 300, 900],
                // Long on purpose. A tax submission that takes two minutes is a slow
                // government API, not a stuck worker.
                'timeout' => 180,
            ],
        ],

        'staging' => [
            'supervisor-default' => [
                'connection' => 'redis',
                'queue' => ['default', 'sms', 'moadian'],
                'balance' => 'auto',
                'maxProcesses' => 3,
                'memory' => 256,
                'tries' => 3,
                'timeout' => 180,
            ],
        ],

        'local' => [
            'supervisor-default' => [
                'connection' => 'redis',
                'queue' => ['default', 'sms', 'moadian'],
                'balance' => 'auto',
                'maxProcesses' => 3,
                'tries' => 1,
                'timeout' => 180,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Watcher Configuration
    |--------------------------------------------------------------------------
    |
    | The following list of directories and files will be watched when using
    | the `horizon:listen` command. Whenever any directories or files are
    | changed, Horizon will automatically restart to apply all changes.
    |
    */

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];
