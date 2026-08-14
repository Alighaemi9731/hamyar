<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Contracts\SmsDriver;
use App\Modules\Messaging\Drivers\FakeSmsDriver;
use App\Modules\Messaging\Jobs\SendSmsJob;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageOptOut;
use App\Modules\Messaging\Services\SendSms;
use App\Modules\Messaging\Services\SmsWallet;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

/**
 * Two shops' messages on one worker.
 *
 * ## Why this test is adversarial rather than illustrative
 *
 * The failure it guards against is not an error. A worker that processes tenant A and then
 * tenant B without clearing the connection variable runs B's message against A's RLS
 * context: A's wallet is charged, A's opt-out list is consulted, A's templates are read.
 * Everything "succeeds". The bill arrives at the wrong shop and nobody finds out until
 * somebody reconciles credit against messages sent.
 *
 * A single-tenant test cannot see it — with one tenant in the database, a stale context is
 * indistinguishable from a correct one. So these tests interleave two tenants deliberately
 * and assert on which shop paid.
 *
 * ## Real jobs through a real worker
 *
 * The `database` queue driver, dispatched from inside each tenant's context and processed
 * from *outside* any tenant context, which is what a worker actually is. The `sync` driver
 * would prove nothing here: it runs the job at the dispatch site, where the right context
 * is already established for the wrong reason.
 *
 * Horizon is a supervisor over these same queues and lands in Phase 11; it does not change
 * the mechanism under test, which is `RestoreTenantContext`.
 *
 * ## What these tests were verified to catch, and what they are not
 *
 * Checked by breaking the middleware deliberately and re-running:
 *
 * - Forcing every job onto the wrong tenant fails **three** of these tests. That is the
 *   failure that matters — the wrong shop's wallet charged, the wrong opt-out list read —
 *   and it is genuinely guarded.
 * - Removing the `forget()` in the middleware's `finally` fails **none** of them, because
 *   `runFor()` already restores the previous context and the `forget()` is belt-and-braces
 *   on top. The "worker holds no tenant afterwards" assertion below is therefore a
 *   regression guard against a future change to `runFor()`, not the primary defence.
 *   Recorded rather than dressed up: a test whose strength is overstated is worse than one
 *   whose limits are written down.
 */
beforeEach(function (): void {
    /*
    | The connection is named explicitly on BOTH sides — dispatch and worker — rather than
    | changed via `queue.default`.
    |
    | The suite runs on `sync`. Flipping the default in a `beforeEach` works when this file
    | runs alone and not inside the full suite, where the queue manager has already resolved
    | and cached a connection: the dispatch then lands in the `jobs` table while the worker
    | drains `sync`, nothing runs, and every assertion here fails with "no message" — which
    | is what happened, and looked like a tenancy bug rather than a test-harness one.
    */
    app(PlanCatalogueSeeder::class)->sync();

    $this->alpha = Tenant::factory()->withDomain()->create(['name' => 'موبایل الفا']);
    $this->beta = Tenant::factory()->withDomain()->create(['name' => 'موبایل بتا']);

    foreach ([$this->alpha, $this->beta] as $tenant) {
        subscribe($tenant, 'pro');
    }

    app(SubscriptionResolver::class)->forget();

    foreach ([$this->alpha, $this->beta] as $tenant) {
        app(TenantProvisioner::class)->seedRoles($tenant);

        inTenantContext($tenant, function (): void {
            User::factory()->create()->assignRole('Owner');
        });
    }

    /** @var FakeSmsDriver $driver */
    $driver = app(SmsDriver::class);
    $driver->reset();
    $this->driver = $driver;

    app(TenantContext::class)->forget();
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * Queue a message on the database connection, whatever the suite's default is.
 *
 * @param  list<string>  $tokens
 */
function dispatchOnDatabase(?string $phone, string $templateId, array $tokens, ?string $idempotencyKey = null): void
{
    SendSmsJob::dispatch($phone, $templateId, $tokens, idempotencyKey: $idempotencyKey)
        ->onConnection('database');
}

/**
 * Drain the queue the way a worker does — from no tenant context at all.
 */
function drainQueue(): void
{
    app(TenantContext::class)->forget();

    Illuminate\Support\Facades\Artisan::call('queue:work', [
        'connection' => 'database',
        // `--queue=sms`, because SendSmsJob puts itself there. Draining `default` finds
        // nothing and every assertion below then passes vacuously — which is how the first
        // draft of this file reported green while processing no jobs at all.
        '--queue' => 'sms',
        '--stop-when-empty' => true,
        '--tries' => 1,
        /*
        | The memory ceiling, and it is not optional.
        |
        | `queue:work` defaults to 128 MB and quits after the current job once the PROCESS
        | exceeds it — not the job. Run alone this file is well under; run inside the full
        | suite the process is already past 128 MB before the first job, so the worker
        | processed exactly one job per call and stopped. Three of these tests then failed
        | with "no message", which reads as a tenancy bug and is a harness one.
        */
        '--memory' => 4096,
    ]);
}

/* ============================ THE INTERLEAVING ============================ */

it('charges each shop its own wallet when their jobs interleave on one worker', function (): void {
    // Both shops fund their wallets. Different amounts, so a mix-up is visible.
    inTenantContext($this->alpha, fn () => app(SmsWallet::class)->topUp(1_000_000));
    inTenantContext($this->beta, fn () => app(SmsWallet::class)->topUp(500_000));

    // Alpha queues two, Beta queues two, alternating — the order a busy afternoon produces.
    inTenantContext($this->alpha, fn () => dispatchOnDatabase('09121110001', 'repair-ready', ['الفا-۱']));
    inTenantContext($this->beta, fn () => dispatchOnDatabase('09122220001', 'repair-ready', ['بتا-۱']));
    inTenantContext($this->alpha, fn () => dispatchOnDatabase('09121110002', 'repair-ready', ['الفا-۲']));
    inTenantContext($this->beta, fn () => dispatchOnDatabase('09122220002', 'repair-ready', ['بتا-۲']));

    drainQueue();

    $cost = SendSms::DEFAULT_SEGMENT_COST;

    // Two messages each, charged to the shop that sent them. If the context leaked, one
    // wallet would be down by four messages and the other untouched — and both sends would
    // have "succeeded".
    inTenantContext($this->alpha, function () use ($cost): void {
        expect(app(SmsWallet::class)->balance())->toBe(1_000_000 - 2 * $cost)
            ->and(Message::query()->count())->toBe(2);
    });

    inTenantContext($this->beta, function () use ($cost): void {
        expect(app(SmsWallet::class)->balance())->toBe(500_000 - 2 * $cost)
            ->and(Message::query()->count())->toBe(2);
    });
});

it('reads each shop own opt-out list, not whichever ran last', function (): void {
    inTenantContext($this->alpha, fn () => app(SmsWallet::class)->topUp(1_000_000));
    inTenantContext($this->beta, fn () => app(SmsWallet::class)->topUp(1_000_000));

    // The SAME number opted out of Alpha only. Beta has every right to text them.
    inTenantContext($this->alpha, fn () => MessageOptOut::query()->create([
        'phone' => '+989121234567',
        'opted_out_at' => CarbonImmutable::now(),
    ]));

    inTenantContext($this->alpha, fn () => dispatchOnDatabase('09121234567', 'birthday', ['از الفا']));
    inTenantContext($this->beta, fn () => dispatchOnDatabase('09121234567', 'birthday', ['از بتا']));

    drainQueue();

    // Alpha suppressed, Beta sent. A leaked context makes this test fail in whichever
    // direction the jobs happened to run — which is exactly the point.
    inTenantContext($this->alpha, function (): void {
        expect(Message::query()->firstOrFail()->status)->toBe(Message::STATUS_SUPPRESSED);
    });

    inTenantContext($this->beta, function (): void {
        expect(Message::query()->firstOrFail()->status)->toBe(Message::STATUS_SENT);
    });

    $this->driver->assertSent('+989121234567', 'birthday', ['از بتا']);
    $this->driver->assertSentCount(1);
});

it('leaves the worker holding no tenant between jobs', function (): void {
    inTenantContext($this->alpha, fn () => app(SmsWallet::class)->topUp(1_000_000));
    inTenantContext($this->alpha, fn () => dispatchOnDatabase('09121110001', 'repair-ready', ['الفا']));

    drainQueue();

    // The condition ADR 0007 is about: a session-scoped variable that outlives its job
    // makes the NEXT job — possibly another shop's — run in the wrong context.
    expect(app(TenantContext::class)->id())->toBeNull();
});

/* ============================== RETRIES ============================== */

it('restores context on a retry after the first attempt failed', function (): void {
    inTenantContext($this->beta, fn () => app(SmsWallet::class)->topUp(1_000_000));

    // The gateway refuses the first attempt. `SendSms` records it and refunds — it does
    // not throw — so the retry path here is the queue's, exercised by pushing the same job
    // twice with the same key, which is what a crashed worker produces.
    $this->driver->failNext('سامانه در دسترس نیست');

    inTenantContext($this->beta, fn () => dispatchOnDatabase(
        '09122220001', 'repair-ready', ['بتا'], idempotencyKey: 'retry-probe:1',
    ));

    drainQueue();

    inTenantContext($this->beta, function (): void {
        /** @var Message $message */
        $message = Message::query()->firstOrFail();

        // Failed, and the money came back — in the right shop's wallet.
        expect($message->status)->toBe(Message::STATUS_FAILED)
            ->and(app(SmsWallet::class)->balance())->toBe(1_000_000);
    });

    // The worker crashed and the job is re-queued. Same key.
    inTenantContext($this->beta, fn () => dispatchOnDatabase(
        '09122220001', 'repair-ready', ['بتا'], idempotencyKey: 'retry-probe:1',
    ));

    drainQueue();

    inTenantContext($this->beta, function (): void {
        // Still one message and still one shop's wallet. A retry that re-charged would be
        // invisible until somebody reconciled credit against messages.
        expect(Message::query()->count())->toBe(1)
            ->and(app(SmsWallet::class)->balance())->toBe(1_000_000);
    });

    inTenantContext($this->alpha, function (): void {
        // And nothing landed on the other shop.
        expect(Message::query()->count())->toBe(0)
            ->and(app(SmsWallet::class)->balance())->toBe(0);
    });
});

it('refuses to run a tenant-aware job that lost its tenant', function (): void {
    // Dispatched from no tenant context at all — a dispatch-site bug.
    $job = new SendSmsJob('09121110001', 'repair-ready', ['بی‌صاحب']);

    expect($job->tenantId)->toBeNull();

    // Failing loudly beats running it: under RLS the job would read nothing, write
    // nothing, and report success.
    expect(fn () => app(App\Support\Tenancy\RestoreTenantContext::class)->handle($job, fn () => null))
        ->toThrow(RuntimeException::class);
});

it('keeps one shop messages invisible to the other', function (): void {
    inTenantContext($this->alpha, fn () => app(SmsWallet::class)->topUp(1_000_000));
    inTenantContext($this->alpha, fn () => dispatchOnDatabase('09121110001', 'repair-ready', ['الفا']));

    drainQueue();

    // Alpha's message exists FIRST. Without this line the assertion below is true whenever
    // the queue silently failed to run, which is the failure mode this whole file is about.
    inTenantContext($this->alpha, function (): void {
        expect(Message::query()->count())->toBe(1);
    });

    inTenantContext($this->beta, function (): void {
        expect(Message::query()->count())->toBe(0);
    });
});
