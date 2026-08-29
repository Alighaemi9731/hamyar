<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services\Quota;

use App\Modules\Platform\Models\UsageCounter;
use App\Modules\Platform\Models\UsageEvent;
use App\Support\Jalali;
use App\Support\Quota\Events\LimitReached;
use App\Support\Quota\Events\QuotaWarning;
use App\Support\Quota\QuotaVerdict;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Writes the pricing signal: warned, blocked, converted.
 *
 * ## Once per credit per period, enforced by an index
 *
 * A shop that crosses 80% and then does ninety more things must be told once. Two workers
 * crossing the line in the same second must write once. A memo in PHP is wrong across
 * processes, so the unique index `(tenant_id, metric, period_key, kind)` is the arbiter
 * and the insert catches its violation.
 *
 * ## The savepoint rule, and why the `try` is outside
 *
 * Postgres aborts the whole transaction on a constraint violation, so catching 23505
 * inside one leaves it dead and every later statement fails with 25P02. Wrapping the
 * insert in `DB::transaction()` gives it a SAVEPOINT to roll back to — but only if the
 * closure THROWS, which means the `try` has to sit outside the wrapper. CLAUDE.md records
 * three places that learned this; this is the fourth, written correctly the first time.
 *
 * ## Why `blocked()` is called after the rollback, never from inside it
 *
 * A refusal throws inside the caller's transaction and unwinds every write it was
 * guarding. An `afterCommit` callback registered in there is discarded by Laravel on
 * rollback, so the most commercially interesting event in the product would silently not
 * exist. The exception renderer calls this once the transaction is fully unwound and the
 * connection is healthy again.
 */
final class UsageEvents
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly TenantContext $context,
        private readonly LimitResolver $limits,
        private readonly Dispatcher $events,
    ) {}

    /**
     * The shop crossed the warning line. Safe to call on every consume.
     */
    public function warning(int $tenantId, QuotaVerdict $verdict): void
    {
        if ($this->insertOnce($tenantId, $verdict, UsageEvent::KIND_WARNING)) {
            $this->events->dispatch(new QuotaWarning($tenantId, $verdict));
        }
    }

    /**
     * The shop was actually stopped.
     *
     * Also stamps `usage_counters.blocked_at`, which is what lets the shared prop tell a
     * meter that is merely full from one that has refused work — without a second query
     * on every staff page.
     */
    public function blocked(int $tenantId, QuotaVerdict $verdict, ?int $userId = null): void
    {
        $kind = $verdict->requested > 1 ? UsageEvent::KIND_BULK_BLOCKED : UsageEvent::KIND_BLOCKED;

        $written = $this->insertOnce($tenantId, $verdict, $kind, $userId);

        if ($verdict->periodKey !== null) {
            $this->context->runAsPlatform(fn (): int => UsageCounter::query()
                ->where('tenant_id', $tenantId)
                ->where('metric', $verdict->metric)
                ->where('period_key', $verdict->periodKey)
                ->whereNull('blocked_at')
                ->update(['blocked_at' => CarbonImmutable::now()]));
        }

        if ($written) {
            $this->events->dispatch(new LimitReached($tenantId, $verdict, $userId));
        }
    }

    /**
     * The shop upgraded within a week of being blocked — the conversion, attributed to the
     * metric that stopped it.
     *
     * Attribution rather than a join: `subscription_invoices` knows what was bought and
     * nothing knows why. Without this row, "which limit sells upgrades" is unanswerable,
     * and that is the one question the pricing depends on.
     */
    public function upgradedAfterBlock(int $tenantId, string $planCode): void
    {
        $recent = $this->context->runAsPlatform(fn (): ?UsageEvent => UsageEvent::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('kind', [UsageEvent::KIND_BLOCKED, UsageEvent::KIND_BULK_BLOCKED])
            ->where('created_at', '>=', CarbonImmutable::now()->subDays(7))
            ->orderByDesc('id')
            ->first());

        if (! $recent instanceof UsageEvent) {
            return;
        }

        $this->write([
            'tenant_id' => $tenantId,
            'metric' => $recent->metric,
            'kind' => UsageEvent::KIND_UPGRADED_AFTER,
            // Keyed to the period the BLOCK happened in, so the pair sits together and
            // the unique index stops a second attribution for the same block.
            'period_key' => $recent->period_key,
            'used' => $recent->used,
            'limit_value' => $recent->limit_value,
            'requested' => $recent->requested,
            'plan_code' => $planCode,
            'user_id' => null,
        ]);
    }

    private function insertOnce(int $tenantId, QuotaVerdict $verdict, string $kind, ?int $userId = null): bool
    {
        return $this->write([
            'tenant_id' => $tenantId,
            'metric' => $verdict->metric,
            'kind' => $kind,
            // A standing capacity has no period; it still gets one row, keyed to the
            // month it happened in, so "we blocked this shop on seats in Shahrivar" is
            // answerable and does not repeat every day of the month.
            'period_key' => $verdict->periodKey ?? $this->fallbackPeriodKey(),
            'used' => $verdict->used,
            'limit_value' => $verdict->limit,
            'requested' => max(1, $verdict->requested),
            'plan_code' => $this->limits->effectivePlanCode($tenantId),
            'user_id' => $userId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return bool true when this is the first of its kind this period
     */
    private function write(array $attributes): bool
    {
        try {
            // The `try` is OUTSIDE `transaction()` on purpose (CLAUDE.md): the closure has
            // to throw for the savepoint to roll back, so catching inside it would leave
            // an aborted transaction and every later statement would fail with 25P02.
            $this->connection->transaction(fn (): bool => $this->context->runAsPlatform(
                static fn (): bool => (bool) UsageEvent::query()->create([
                    ...$attributes,
                    'created_at' => CarbonImmutable::now(),
                ])
            ));

            return true;
        } catch (UniqueConstraintViolationException) {
            // Already recorded this period. Not an error — it is the index doing the job
            // a PHP memo could not do across processes.
            return false;
        }
    }

    private function fallbackPeriodKey(): string
    {
        return Jalali::startOfMonth(CarbonImmutable::now())->toDateString();
    }
}
