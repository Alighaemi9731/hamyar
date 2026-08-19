<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Observability\HealthCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `/health` — the deep check, beside Laravel's own `/up`.
 *
 * ## Why both
 *
 * `/up` (wired in `bootstrap/app.php`) boots the framework and returns. That is a
 * genuine liveness signal — it catches a container that is running but wedged — and it
 * touches nothing, so it is safe to hit every second forever.
 *
 * This one asks whether the dependencies are there. It costs a database round trip, a
 * Redis round trip and a read of the migration repository, which is cheap but not
 * free, and it is the endpoint `bin/deploy` waits on before moving traffic.
 *
 * ## The body is not public
 *
 * An unauthenticated caller gets `{"status":"ok"}` or `{"status":"unhealthy"}` and the
 * status code. That is everything an uptime probe needs and nothing an attacker can
 * use.
 *
 * The detailed body names internal hostnames, driver classes and the exact shape of a
 * failure — `SQLSTATE[08006] … could not connect to server: db-internal:5432`. Published
 * on the open internet that is free reconnaissance, and it is worst precisely when
 * things are broken and somebody is already looking.
 *
 * So details need `X-Health-Secret`, and with no `HEALTH_SECRET` configured **nobody**
 * gets them. Failing closed matters more than convenience here: the alternative
 * default — no secret set, so show everyone — is a box that leaks its topology because
 * of a variable somebody did not know to set.
 */
final class HealthController extends Controller
{
    public function __invoke(Request $request, HealthCheck $health): JsonResponse
    {
        $result = $health->run();

        $body = ['status' => $result['healthy'] ? 'ok' : 'unhealthy'];

        if ($this->maySeeDetails($request)) {
            $body['checks'] = $result['checks'];
        }

        return response()
            ->json($body, $result['healthy'] ? 200 : 503)
            // A cached health check is not a health check. Proxies and CDNs will
            // happily serve a 200 from thirty seconds ago on a box that has since died.
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->header('Pragma', 'no-cache');
    }

    private function maySeeDetails(Request $request): bool
    {
        $secret = config('app.health_secret');

        if (! is_string($secret) || $secret === '') {
            return false;
        }

        $presented = $request->header('X-Health-Secret');

        if (! is_string($presented) || $presented === '') {
            return false;
        }

        // Constant-time: the header is compared on every probe, and a byte-by-byte
        // comparison of a fixed secret against attacker-chosen input is the textbook
        // way to hand it over one character at a time.
        return hash_equals($secret, $presented);
    }
}
