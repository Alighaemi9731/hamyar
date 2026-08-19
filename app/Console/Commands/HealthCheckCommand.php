<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Observability\HealthCheck;
use Illuminate\Console\Command;

/**
 * The same health check `/health` serves, over the command line.
 *
 * ## Why this exists as well as the endpoint
 *
 * `bin/deploy` has to know whether the container it just started is fit to receive
 * traffic, and it has to know that **before** nginx points at it. It cannot ask over
 * HTTP: the application container runs php-fpm, which speaks FastCGI and not HTTP, so
 * there is nothing inside it to curl. Going through nginx would mean cutting over
 * first and checking afterwards, which is the opposite of the point.
 *
 * So the deploy runs this. Same {@see HealthCheck}, same grading, no HTTP layer — and
 * the exit code is the answer, which is what a shell script can actually act on.
 *
 * It is also the thing to run by hand at 3am, where `--details` beats reading JSON out
 * of a curl.
 */
final class HealthCheckCommand extends Command
{
    protected $signature = 'health:check {--json : Emit the raw result instead of a table}';

    protected $description = 'Check database, cache, migrations and queue; exit non-zero if something critical is down';

    public function handle(HealthCheck $health): int
    {
        $result = $health->run();

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $result['healthy'] ? self::SUCCESS : self::FAILURE;
        }

        $rows = [];

        foreach ($result['checks'] as $name => $check) {
            $rows[] = [
                $check['ok'] ? '<fg=green>ok</>' : '<fg=red>FAIL</>',
                $name,
                // Stated per row, because the grading is the part a reader is most
                // likely to be surprised by: a failing queue check is not an outage.
                $check['critical'] ? 'critical' : 'reportable',
                $check['detail'],
            ];
        }

        $this->table(['', 'check', 'grade', 'detail'], $rows);

        if (! $result['healthy']) {
            $this->error('Unhealthy: a critical dependency is down. This container must not receive traffic.');

            return self::FAILURE;
        }

        $this->info('Healthy.');

        return self::SUCCESS;
    }
}
