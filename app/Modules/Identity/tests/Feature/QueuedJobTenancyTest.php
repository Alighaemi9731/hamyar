<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\TenantAware;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * A worker process handles job after job for different shops inside one container.
 * If the tenant context leaked between them, a job would write into the wrong shop —
 * the worst failure mode this system has, and one that leaves no trace at the time.
 */
pest()->group('isolation');

/**
 * Counts the users the job can see, under whatever context it runs with.
 */
final class CountVisibleUsersJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TenantAware;

    /** @var array<string, int> */
    public static array $seen = [];

    public function __construct(public readonly string $label)
    {
        $this->initializeTenantAware();
    }

    public function handle(): void
    {
        self::$seen[$this->label] = User::query()->count();
    }
}

beforeEach(function (): void {
    CountVisibleUsersJob::$seen = [];

    $this->context = app(TenantContext::class);

    $this->alpha = Tenant::factory()->create();
    $this->beta = Tenant::factory()->create();

    $this->context->runFor($this->alpha, fn () => User::factory()->count(3)->create());
    $this->context->runFor($this->beta, fn () => User::factory()->count(1)->create());
});

afterEach(fn () => app(TenantContext::class)->forget());

it('runs a queued job under the tenant that dispatched it', function (): void {
    $job = $this->context->runFor($this->alpha, fn () => new CountVisibleUsersJob('alpha'));

    expect($job->tenantId)->toBe($this->alpha->getKey());

    // Executed with NO ambient context, exactly as a worker would.
    $this->context->forget();
    dispatch($job);

    expect(CountVisibleUsersJob::$seen['alpha'])->toBe(3);
});

it('does not leak context between two jobs on the same worker', function (): void {
    $alphaJob = $this->context->runFor($this->alpha, fn () => new CountVisibleUsersJob('alpha'));
    $betaJob = $this->context->runFor($this->beta, fn () => new CountVisibleUsersJob('beta'));

    $this->context->forget();

    dispatch($alphaJob);
    dispatch($betaJob);

    expect(CountVisibleUsersJob::$seen)->toBe(['alpha' => 3, 'beta' => 1]);
});

it('leaves the worker with no tenant after a job finishes', function (): void {
    $job = $this->context->runFor($this->alpha, fn () => new CountVisibleUsersJob('alpha'));

    $this->context->forget();
    dispatch($job);

    // A worker left holding a tenant would silently apply it to whatever ran next,
    // including code that never asked for a tenant at all.
    expect($this->context->id())->toBeNull();
});

it('fails loudly when a tenant-aware job carries no tenant', function (): void {
    // Silently running such a job is worse than failing: RLS returns nothing, the job
    // "succeeds" having done nothing, and nobody finds out until a customer asks why
    // their SMS never arrived.
    $this->context->forget();
    $job = new CountVisibleUsersJob('orphan');

    expect(fn () => dispatch($job))->toThrow(RuntimeException::class);
});

it('skips a job whose tenant has been deleted', function (): void {
    $job = $this->context->runFor($this->beta, fn () => new CountVisibleUsersJob('gone'));

    $this->context->forget();
    $this->beta->forceDelete();

    dispatch($job);

    expect(CountVisibleUsersJob::$seen)->not->toHaveKey('gone');
});
