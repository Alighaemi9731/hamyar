<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services\Quota;

use App\Modules\Platform\Models\UsageCounter;
use App\Support\Quota\Metric;
use App\Support\Quota\MetricRegistry;
use App\Support\Quota\OutsideTransaction;
use App\Support\Quota\PeriodClock;
use App\Support\Quota\QuotaExceeded;
use App\Support\Quota\QuotaGuard;
use App\Support\Quota\QuotaVerdict;
use App\Support\Quota\Window;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * The real guard: one Postgres row per credit, one statement per spend.
 *
 * ## Why check-and-increment is a single statement
 *
 * The obvious implementation reads the counter, decides in PHP, and writes. At the last
 * unit of a credit, two requests both read "one left", both decide yes, and the shop gets
 * two for one — silently, on the busiest day, which is exactly when it happens. `SELECT …
 * FOR UPDATE` fixes that and costs a second round trip plus a create-on-miss race on the
 * first spend of a period (`CounterService` has that race today, unwrapped by a savepoint).
 *
 * So the spend is:
 *
 *     INSERT … SELECT … WHERE n <= limit
 *     ON CONFLICT (tenant_id, metric, period_key)
 *     DO UPDATE SET used = used + EXCLUDED.used WHERE used + EXCLUDED.used <= limit
 *     RETURNING used
 *
 * `ON CONFLICT` **is** the arbiter, so a first spend cannot 23505; the second of two
 * concurrent spenders waits on the first one's tuple and then re-evaluates the `WHERE`
 * against its committed value; and zero rows back means refused with nothing written. The
 * two arms compose exactly: `n > limit` refuses always (no row is proposed, so no conflict
 * path either), `n <= limit` with no row inserts, `n <= limit` with a row updates only if
 * it still fits.
 *
 * ## Why every placeholder carries a cast
 *
 * The insert arm is `INSERT … SELECT ?, ?, …` rather than `INSERT … VALUES`, and Postgres
 * cannot infer a parameter's type outside a VALUES column position — it fails at prepare
 * time with «could not determine data type of parameter». Each `?::type` is load-bearing,
 * not decoration. The unbounded statement uses VALUES and needs none.
 *
 * ## Why unlimited still counts
 *
 * A shop on the top rung still increments its counters. Unlimited means "never refused",
 * not "never measured": the meters on the billing page, the usage screen in the panel and
 * every number the pricing decisions will be made from depend on the row existing. A
 * separate no-op path for unlimited would save one statement and blind us to our biggest
 * customers.
 */
final class DatabaseQuotaGuard implements QuotaGuard
{
    /**
     * Unlimited: count it, never refuse it. `VALUES` needs no casts.
     */
    private const SQL_UNBOUNDED = <<<'SQL'
        INSERT INTO usage_counters (tenant_id, metric, period_key, used, first_used_at, last_used_at)
        VALUES (?, ?, ?, ?, now(), now())
        ON CONFLICT (tenant_id, metric, period_key)
        DO UPDATE SET used = usage_counters.used + EXCLUDED.used, last_used_at = now()
        RETURNING used
        SQL;

    /**
     * Capped: both arms guarded in SQL. Zero rows back = refused, nothing written.
     */
    private const SQL_BOUNDED = <<<'SQL'
        INSERT INTO usage_counters (tenant_id, metric, period_key, used, first_used_at, last_used_at)
        SELECT ?::bigint, ?::varchar, ?::varchar, ?::bigint, now(), now()
        WHERE ?::bigint <= ?::bigint
        ON CONFLICT (tenant_id, metric, period_key)
        DO UPDATE SET used = usage_counters.used + EXCLUDED.used, last_used_at = now()
        WHERE usage_counters.used + EXCLUDED.used <= ?::bigint
        RETURNING used
        SQL;

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly TenantContext $context,
        private readonly MetricRegistry $registry,
        private readonly LimitResolver $limits,
        private readonly PeriodClock $clock,
    ) {}

    public function check(string $metric, int $n = 1): QuotaVerdict
    {
        $definition = $this->registry->get($metric);
        $tenantId = $this->context->idOrFail();
        $limit = $this->limits->forTenant($tenantId, $metric);

        $used = $definition->isCounted()
            ? $this->currentUsed($tenantId, $metric, $this->clock->periodKey($definition->window))
            : $definition->measure($tenantId);

        return $this->verdict(
            $definition,
            used: $used,
            limit: $limit,
            requested: $n,
            allowed: $limit === null || $used + $n <= $limit,
            tenantId: $tenantId,
        );
    }

    public function consume(string $metric, int $n = 1): QuotaVerdict
    {
        if ($n < 1) {
            throw new InvalidArgumentException("Cannot consume {$n} of [{$metric}]: a spend is at least one unit.");
        }

        if ($this->connection->transactionLevel() === 0) {
            throw new OutsideTransaction($metric);
        }

        $definition = $this->registry->get($metric);
        $tenantId = $this->context->idOrFail();

        if (! $definition->isCounted()) {
            return $this->assertCapacity($definition, $tenantId, $n);
        }

        $limit = $this->limits->forTenant($tenantId, $metric);
        $periodKey = $this->clock->periodKey($definition->window);

        if ($limit === null) {
            /** @var object{used: int|string}|null $row */
            $row = $this->connection->selectOne(self::SQL_UNBOUNDED, [$tenantId, $metric, $periodKey, $n]);

            return $this->verdict($definition, (int) ($row->used ?? $n), null, $n, true, $tenantId, $periodKey);
        }

        /** @var object{used: int|string}|null $row */
        $row = $this->connection->selectOne(
            self::SQL_BOUNDED,
            [$tenantId, $metric, $periodKey, $n, $n, $limit, $limit]
        );

        if ($row === null) {
            // Refused. Nothing was written, so `used` has to be read for the message —
            // one extra SELECT on the path that is about to show a human a screen, which
            // is the cheapest place in the system to spend a query.
            throw new QuotaExceeded($this->verdict(
                $definition,
                used: $this->currentUsed($tenantId, $metric, $periodKey),
                limit: $limit,
                requested: $n,
                allowed: false,
                tenantId: $tenantId,
                periodKey: $periodKey,
            ));
        }

        $verdict = $this->verdict($definition, (int) $row->used, $limit, $n, true, $tenantId, $periodKey);

        /*
        | The 80 % line, announced once per credit per period.
        |
        | `afterCommit` because a warning that fires for a sale which then rolls back is a
        | lie — and unlike the BLOCK event, a warning that dies with its transaction costs
        | nothing: the next spend crosses the same line and announces it then. The block
        | cannot use afterCommit for exactly the opposite reason (§8 of ADR 0018).
        |
        | The once-per-period part is enforced by a unique index inside `UsageEvents`, not
        | by remembering here: two workers can cross the line in the same second, and a
        | memo in PHP is wrong across processes.
        */
        if ($this->crossedWarning($verdict, $used = (int) $row->used, $n)) {
            DB::afterCommit(fn () => $this->events()->warning($tenantId, $verdict));
        }

        return $verdict;
    }

    /**
     * Did THIS spend take the shop across the warning line?
     *
     * "Across", not "above": a shop already past the line does not need telling again on
     * every subsequent spend, and the index would refuse the row anyway — but checking
     * here saves the write and the event on the common path.
     */
    private function crossedWarning(QuotaVerdict $verdict, int $used, int $n): bool
    {
        if ($verdict->limit === null || $verdict->limit === 0) {
            return false;
        }

        $line = (int) ceil(config()->float('hamyar.quota.warning_ratio', 0.8) * $verdict->limit);

        return $used >= $line && ($used - $n) < $line;
    }

    /**
     * Resolved lazily rather than injected: `UsageEvents` needs `LimitResolver`, which
     * needs this guard's collaborators, and constructor-injecting it would close a
     * container loop that only exists on the warning path.
     */
    private function events(): UsageEvents
    {
        return app(UsageEvents::class);
    }

    public function record(string $metric, int $n = 1): QuotaVerdict
    {
        try {
            // Its own transaction: `record()` is called from jobs and sweeps that have no
            // domain write to join, and the statement still needs one to be atomic.
            /** @var QuotaVerdict $verdict */
            $verdict = $this->connection->transaction(fn (): QuotaVerdict => $this->consume($metric, $n));

            return $verdict;
        } catch (QuotaExceeded $exceeded) {
            // The whole point: automated work never throws. The caller reads the verdict
            // and decides — `SendSms` marks the message suppressed with a reason the shop
            // can read in its own log, rather than a job retrying until it alerts.
            return $exceeded->verdict;
        }
    }

    /**
     * Every counted metric's current standing, for the shared `usage` prop.
     *
     * ## Why this one is lenient when `consume()` is not
     *
     * `LimitResolver` throws when it cannot work out what a shop is allowed — a missing
     * fallback plan is a misconfiguration, and refusing to *write* without knowing the
     * limit is the safe direction.
     *
     * Reading is the other direction. This runs on every staff page render, so the same
     * strictness would turn an unseeded catalogue into a white screen for the whole
     * application — the meter is a convenience, and a convenience must never be able to
     * take the product down. So a failure here is reported (so it is loud where failures
     * are watched) and answered with no meters, which the frontend already renders as
     * nothing at all.
     *
     * @return list<QuotaVerdict>
     */
    public function snapshot(): array
    {
        $tenantId = $this->context->id();

        if ($tenantId === null) {
            return [];
        }

        try {
            return $this->meters($tenantId);
        } catch (Throwable $failure) {
            report($failure);

            return [];
        }
    }

    /**
     * @return list<QuotaVerdict>
     */
    private function meters(int $tenantId): array
    {

        $counted = $this->registry->counted();

        if ($counted === []) {
            return [];
        }

        $periodKey = $this->clock->periodKey(Window::Month);

        // One query for every meter on the page — the index on (tenant_id, period_key)
        // covers it, and the alternative is a query per metric on every staff request.
        /** @var array<string, UsageCounter> $rows */
        $rows = $this->context->runAsPlatform(
            fn (): array => UsageCounter::query()
                ->where('tenant_id', $tenantId)
                ->where('period_key', $periodKey)
                ->get(['metric', 'used', 'blocked_at'])
                ->keyBy('metric')
                ->all()
        );

        $verdicts = [];

        foreach ($counted as $definition) {
            $row = $rows[$definition->key] ?? null;

            $verdicts[] = $this->verdict(
                $definition,
                used: $row instanceof UsageCounter ? $row->used : 0,
                limit: $this->limits->forTenant($tenantId, $definition->key),
                requested: 0,
                allowed: true,
                tenantId: $tenantId,
                periodKey: $periodKey,
            );
        }

        return $verdicts;
    }

    /**
     * A standing capacity — seats, branches, storage, live links.
     *
     * No counter row: usage is however many live rows exist, which the owning module's
     * closure counts. The advisory lock is what makes "measure, then create" safe: two
     * simultaneous invitations at the last seat would both measure five-of-six and both
     * proceed. It is transaction-scoped, so it releases at commit with no unlock to forget.
     */
    private function assertCapacity(Metric $definition, int $tenantId, int $n): QuotaVerdict
    {
        // hashtext() over tenant+metric: a collision merely serialises two unrelated
        // writes for the length of a transaction, which is a performance non-event.
        $this->connection->select(
            "SELECT pg_advisory_xact_lock(hashtext('quota:' || ?::text || ':' || ?::text))",
            [$tenantId, $definition->key]
        );

        $limit = $this->limits->forTenant($tenantId, $definition->key);
        $used = $definition->measure($tenantId);

        if ($limit !== null && $used + $n > $limit) {
            throw new QuotaExceeded(
                $this->verdict($definition, $used, $limit, $n, false, $tenantId)
            );
        }

        return $this->verdict($definition, $used + $n, $limit, $n, true, $tenantId);
    }

    private function currentUsed(int $tenantId, string $metric, string $periodKey): int
    {
        /** @var object{used: int|string}|null $row */
        $row = $this->connection->selectOne(
            'SELECT used FROM usage_counters WHERE tenant_id = ? AND metric = ? AND period_key = ?',
            [$tenantId, $metric, $periodKey]
        );

        return (int) ($row->used ?? 0);
    }

    private function verdict(
        Metric $definition,
        int $used,
        ?int $limit,
        int $requested,
        bool $allowed,
        int $tenantId,
        ?string $periodKey = null,
    ): QuotaVerdict {
        return new QuotaVerdict(
            metric: $definition->key,
            window: $definition->window,
            used: $used,
            limit: $limit,
            requested: $requested,
            periodKey: $definition->isCounted()
                ? ($periodKey ?? $this->clock->periodKey($definition->window))
                : null,
            resetsAt: $this->clock->resetsAt($definition->window),
            allowed: $allowed,
            // Only worked out when it matters: a refusal aims the upgrade button, and
            // every other verdict would pay a plan query to answer a question nobody asked.
            nextPlanCode: $allowed
                ? null
                : $this->limits->nextPlanFor($tenantId, $definition->key, $used + $requested),
        );
    }
}
