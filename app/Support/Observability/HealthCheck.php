<?php

declare(strict_types=1);

namespace App\Support\Observability;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Throwable;

/**
 * What "is this box healthy?" means, decided once.
 *
 * ## Two questions that must not be confused
 *
 * An endpoint like this is read by two callers who want opposite things, and answering
 * them with one number is how a monitoring system causes the outage it was installed
 * to catch:
 *
 * - **The load balancer and the deploy script** ask *can this container serve a
 *   request?* A "no" removes it from rotation. That is the right response to a dead
 *   database connection and a catastrophic one to a busy queue — pull the web tier
 *   because SMS is slow and the shop cannot take payments either.
 * - **The human on call** asks *is the platform behaving?* That question wants the
 *   queue depth, and wants it without taking anything offline.
 *
 * So checks are graded. {@see self::CRITICAL} failures mean this container cannot do
 * its job and produce a 503. Everything else is reported at 200 and is somebody's
 * pager, not the load balancer's business.
 *
 * ## Everything here is tenant-free
 *
 * No tenant is pinned on a health request, and RLS fails closed — a tenant table
 * queried without context returns zero rows rather than erroring. A check reading one
 * would therefore report perfect health from a database that had lost every row. The
 * checks below touch only central tables and infrastructure.
 */
final class HealthCheck
{
    /**
     * Checks whose failure means "stop sending this container traffic".
     *
     * Deliberately short. Adding to it is how a health endpoint acquires the power to
     * take a working site down.
     *
     * @var list<string>
     */
    private const CRITICAL = ['database', 'cache', 'migrations'];

    /**
     * @return array{healthy: bool, checks: array<string, array{ok: bool, critical: bool, detail: string}>}
     */
    public function run(): array
    {
        $checks = [
            'database' => $this->check(fn (): string => $this->database()),
            'cache' => $this->check(fn (): string => $this->cache()),
            'migrations' => $this->check(fn (): string => $this->migrations()),
            'queue' => $this->check(fn (): string => $this->queue()),
        ];

        $healthy = true;

        foreach ($checks as $name => $result) {
            $critical = in_array($name, self::CRITICAL, true);
            $checks[$name]['critical'] = $critical;

            if ($critical && ! $result['ok']) {
                $healthy = false;
            }
        }

        return ['healthy' => $healthy, 'checks' => $checks];
    }

    /**
     * Run one check, converting a throw into a failed result.
     *
     * A health endpoint that 500s tells the prober "unhealthy" by accident and tells
     * the operator nothing about which of four things broke. Catching here means the
     * answer always has the shape the caller expects.
     *
     * @param  callable(): string  $probe
     * @return array{ok: bool, critical: bool, detail: string}
     */
    private function check(callable $probe): array
    {
        try {
            return ['ok' => true, 'critical' => false, 'detail' => $probe()];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'critical' => false,
                // Class name as well as message: a PDOException and a
                // RuntimeException with the same words mean very different things.
                'detail' => $e::class.': '.$e->getMessage(),
            ];
        }
    }

    /**
     * A round trip to Postgres as the application role.
     *
     * `select 1` rather than a count of anything: it proves the connection, the
     * credentials and the network without depending on a table existing, which is the
     * state a half-run migration leaves the box in.
     */
    private function database(): string
    {
        $started = microtime(true);

        DB::connection()->select('select 1');

        return $this->ms($started);
    }

    /**
     * Redis, through the cache repository rather than a raw ping.
     *
     * The ping proves the socket. This proves the socket *plus* the prefix, the
     * serializer and the credentials the application actually uses — a Redis that
     * answers PING while rejecting AUTH on the configured database looks healthy to a
     * ping and is useless to the product.
     */
    private function cache(): string
    {
        $started = microtime(true);

        $key = 'health:'.bin2hex(random_bytes(8));

        /** @var CacheRepository $cache */
        $cache = app(CacheRepository::class);

        $cache->put($key, 'ok', 10);
        $value = $cache->get($key);
        $cache->forget($key);

        if ($value !== 'ok') {
            throw new RuntimeException('wrote a value to the cache and read back something else');
        }

        return $this->ms($started);
    }

    /**
     * Nothing waiting to be applied.
     *
     * Critical, and it is the one grading choice here that is genuinely arguable. A
     * container running last release's code against this release's schema mostly
     * works — right up to the column it does not know about. During a deploy this is
     * exactly the signal the script needs before it moves nginx onto the new
     * container, so it is worth a 503 in the seconds it is true.
     *
     * `migrations` is a central table with no RLS policy, so this reads correctly with
     * no tenant pinned.
     */
    private function migrations(): string
    {
        /** @var Migrator $migrator */
        $migrator = app('migrator');

        if (! $migrator->repositoryExists()) {
            throw new RuntimeException('the migration repository does not exist — this database was never migrated');
        }

        $pending = array_diff(
            array_map(
                static fn (string $path): string => basename($path, '.php'),
                $migrator->getMigrationFiles($migrator->paths() + [database_path('migrations')]),
            ),
            $migrator->getRepository()->getRan(),
        );

        if ($pending !== []) {
            throw new RuntimeException(count($pending).' migration(s) pending, first: '.(string) reset($pending));
        }

        return 'up to date';
    }

    /**
     * Queue depth — reported, never fatal.
     *
     * The roadmap's alert threshold is a wait over 60s on `sms`, and that is a page,
     * not a de-registration: an SMS backlog does not stop a shop selling a phone. This
     * check exists so the number is on the same screen as everything else, and its
     * grading is the whole reason {@see self::CRITICAL} is a list rather than "all of
     * them".
     */
    private function queue(): string
    {
        $depths = [];

        foreach (['default', 'sms', 'moadian'] as $queue) {
            $depths[] = $queue.'='.Redis::connection('default')->llen('queues:'.$queue);
        }

        return implode(' ', $depths);
    }

    private function ms(float $started): string
    {
        return round((microtime(true) - $started) * 1000, 1).'ms';
    }
}
