/*
 * The ten screens a shop opens, under concurrency, on a launch-sized database.
 *
 * Roadmap 11.2. This is deliberately a different measurement from
 * `ReportLatencyTest`, which times report *services* on one shop's data and is a ceiling
 * on query cost. This times *endpoints*, over HTTP, through nginx and PHP-FPM, with many
 * shops asking at once — so it can see the things that only appear there: connection
 * pool contention, FPM worker starvation, per-request boot cost, and a query whose plan
 * is fine alone and not fine forty times over.
 *
 * ## What it runs against
 *
 * `php artisan platform:seed-volume --tenants=50` — 50 shops, ~19M rows. Each virtual
 * user signs in as a different shop's owner, so the tenant predicate is doing real work
 * rather than selecting the whole table.
 *
 * ## Run
 *
 *   docker run --rm --network mobishop_default -i \
 *     -v "$PWD/tests/Load:/scripts" grafana/k6 run /scripts/endpoints.js
 */
import http from 'k6/http';
import { check, group, sleep } from 'k6';
import { Trend, Rate } from 'k6/metrics';

const BASE = __ENV.BASE_URL || 'http://nginx';
const DOMAIN = __ENV.APP_DOMAIN || 'app.localhost';
const SHOPS = Number(__ENV.SHOPS || 50);

/**
 * The ten a shop actually opens, not the ten that are easiest to hit.
 *
 * `/sales/pos` is first because it is the till and it is open all day; the audit log is
 * here because 11c made it a real screen over a table that grows faster than any other.
 */
const ENDPOINTS = [
    ['pos', '/sales/pos'],
    ['dashboard', '/dashboard'],
    ['invoices', '/sales'],
    ['products', '/catalog'],
    ['prices', '/catalog/prices'],
    ['customers', '/crm'],
    ['inventory', '/inventory'],
    ['repairs board', '/repairs/board'],
    ['sales report', '/reporting/sales'],
    ['audit log', '/settings/activity'],
];

/** One trend per endpoint, so a slow screen is named rather than averaged away. */
const trends = {};
const failures = {};

for (const [name] of ENDPOINTS) {
    trends[name] = new Trend(`screen_${name.replace(/\s+/g, '_')}`, true);
    failures[name] = new Rate(`fail_${name.replace(/\s+/g, '_')}`);
}

export const options = {
    scenarios: {
        shopkeepers: {
            executor: 'ramping-vus',
            startVUs: 0,
            stages: [
                { duration: '20s', target: 20 },
                { duration: '60s', target: 20 },
                { duration: '10s', target: 0 },
            ],
            gracefulRampDown: '10s',
        },
    },
    thresholds: {
        // A page a shopkeeper waits on. 1s at the 95th is the line between "the software
        // is quick" and "the software is thinking", and it is deliberately looser than
        // the 300ms service budget because this figure includes the whole stack.
        http_req_duration: ['p(95)<1000'],
        // Errors are not a percentage game here: a 500 under load is the finding.
        http_req_failed: ['rate<0.01'],
    },
};

/**
 * Sign in as one shop's owner and keep the session for the rest of the iteration.
 *
 * The CSRF token comes from the login page's meta tag rather than a cookie, because that
 * is the one this application's own forms use.
 */
function signIn(host) {
    const page = http.get(`${BASE}/login`, { headers: { Host: host }, tags: { name: 'login:form' } });

    const token = (page.body.match(/name="csrf-token" content="([^"]+)"/) || [])[1];

    if (!token) {
        return false;
    }

    const shopIndex = Number(host.split('.')[0].replace('load-test-', ''));

    const response = http.post(
        `${BASE}/login`,
        {
            _token: token,
            // The seeder's own convention: 0900 + a zero-padded index.
            mobile: `0900${String(shopIndex).padStart(7, '0')}`,
            password: 'password',
        },
        { headers: { Host: host }, redirects: 1, tags: { name: 'login:submit' } },
    );

    return check(response, { 'signed in': (r) => r.status === 200 || r.status === 302 });
}

export default function () {
    // Spread virtual users across shops, so no single tenant's rows are unnaturally warm
    // in the buffer cache and the tenant predicate has to do its job.
    const shop = ((__VU - 1) % SHOPS) + 1;
    const host = `load-test-${shop}.${DOMAIN}`;

    if (!signIn(host)) {
        return;
    }

    for (const [name, path] of ENDPOINTS) {
        group(name, () => {
            const response = http.get(`${BASE}${path}`, {
                headers: { Host: host },
                tags: { name },
            });

            trends[name].add(response.timings.duration);
            failures[name].add(response.status >= 400);

            check(response, {
                [`${name} responded`]: (r) => r.status === 200,
            });
        });

        // A shopkeeper reads the screen before opening the next one. Hammering with no
        // pause measures a benchmark nobody experiences and hides queueing behind it.
        sleep(0.3);
    }
}
