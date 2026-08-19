<?php

declare(strict_types=1);

use App\Support\Observability\HealthCheck;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * `/health` — the endpoint the uptime probe watches and `bin/deploy` waits on.
 *
 * Two properties are worth more than the rest and both are here: it must answer on a
 * hostname no shop owns, and it must not describe its own failures to strangers.
 */
it('answers on a hostname belonging to no shop', function (): void {
    /*
    | The tenant group 404s an unknown host, which is right for a shop screen and would
    | be a disaster here: the monitor would report the platform down whenever it was
    | pointed at an address the `domains` table has not heard of — which is every
    | address `bin/deploy` uses, because it probes the new container directly, before
    | nginx and before DNS know anything about it.
    */
    $this->get('http://a-host-no-tenant-owns.invalid/health')
        ->assertOk()
        ->assertJson(['status' => 'ok']);
});

it('tells an anonymous caller whether it is up and nothing else', function (): void {
    /*
    | The detailed body names internal hostnames, driver classes and the exact shape of
    | a failure — `SQLSTATE[08006] … could not connect to server: db-internal:5432`.
    | That is free reconnaissance, and it reads best precisely when things are broken
    | and somebody is already looking.
    */
    $response = $this->getJson('/health')->assertOk();

    expect($response->json())->toBe(['status' => 'ok']);
});

it('withholds the detail even from a caller with a header, when no secret is set', function (): void {
    // Fails closed. The other default — no secret configured, so show everyone — is a
    // box that publishes its topology because of a variable somebody did not know about.
    config(['app.health_secret' => null]);

    $response = $this->getJson('/health', ['X-Health-Secret' => 'anything'])->assertOk();

    expect($response->json())->not->toHaveKey('checks');
});

it('withholds the detail from a wrong secret', function (): void {
    config(['app.health_secret' => 'the-real-one']);

    $response = $this->getJson('/health', ['X-Health-Secret' => 'not-the-real-one'])->assertOk();

    expect($response->json())->not->toHaveKey('checks');
});

it('hands the detail to a caller holding the secret', function (): void {
    config(['app.health_secret' => 'the-real-one']);

    $response = $this->getJson('/health', ['X-Health-Secret' => 'the-real-one'])->assertOk();

    expect($response->json('checks'))->toHaveKeys(['database', 'cache', 'migrations', 'queue']);
});

it('returns 503 when something critical is down', function (): void {
    /*
    | Broken for real rather than stubbed at the top: `HealthCheck` is asked to talk to
    | a cache that throws, which is what a dead Redis looks like from in here. Stubbing
    | `HealthCheck::run()` itself would assert only that the controller can read a
    | boolean it was handed.
    */
    breakTheCache();

    $this->getJson('/health')
        ->assertStatus(503)
        ->assertJson(['status' => 'unhealthy']);
});

it('forbids caching, because a cached health check is not one', function (): void {
    // A proxy will happily serve a 200 from thirty seconds ago on a box that has since
    // died, and the graph stays green through the outage.
    // Compared as a set: Symfony normalises and reorders the directives, so asserting
    // the string we wrote would be asserting Symfony's sort order.
    $directives = explode(', ', (string) $this->get('/health')->headers->get('Cache-Control'));

    expect($directives)->toContain('no-store', 'no-cache', 'must-revalidate');
});

/* ------------------------------------------------------------------ grading -- */

it('grades a queue backlog as reportable, not as a reason to pull the box', function (): void {
    /*
    | The single most important line in `HealthCheck`, and the one a future edit is most
    | likely to undo by adding `queue` to the critical list "for completeness".
    |
    | An SMS backlog does not stop a shop selling a phone. Grading it critical means the
    | load balancer removes a perfectly healthy web tier because a third-party gateway
    | is slow — converting a delayed text message into a shop that cannot take payment.
    */
    $result = app(HealthCheck::class)->run();

    expect($result['checks']['queue']['critical'])->toBeFalse()
        ->and($result['checks']['database']['critical'])->toBeTrue()
        ->and($result['checks']['cache']['critical'])->toBeTrue()
        ->and($result['checks']['migrations']['critical'])->toBeTrue();
});

it('reports healthy against the real stack', function (): void {
    // The suite runs against a real Postgres and a real Redis, so this is not a mock
    // agreeing with itself — it is the checks doing what they will do in production.
    $result = app(HealthCheck::class)->run();

    expect($result['healthy'])->toBeTrue(
        'unhealthy: '.json_encode($result['checks'], JSON_UNESCAPED_UNICODE),
    );
});

it('survives a check that throws instead of taking the endpoint down with it', function (): void {
    /*
    | A health endpoint that 500s tells the prober "unhealthy" by accident, and tells
    | the operator nothing about which of four things broke — at the one moment the
    | difference matters.
    |
    | The other three checks must still report. A failure that stops the run is a
    | failure that hides whatever was going to be found after it.
    */
    breakTheCache();

    $result = app(HealthCheck::class)->run();

    expect($result['healthy'])->toBeFalse()
        ->and($result['checks']['cache']['ok'])->toBeFalse()
        ->and($result['checks']['cache']['detail'])->toContain('RuntimeException')
        ->and($result['checks']['database']['ok'])->toBeTrue()
        ->and($result['checks'])->toHaveKeys(['database', 'cache', 'migrations', 'queue']);
});

/**
 * Replace the cache the health check reaches for with one that cannot answer.
 *
 * Bound on the contract rather than mocking `HealthCheck`, which is final — and which
 * would be the wrong seam anyway: stubbing the thing under test proves the controller
 * can read a boolean, not that a dead dependency is survived.
 */
function breakTheCache(): void
{
    test()->mock(CacheRepository::class)
        ->shouldReceive('put')
        ->andThrow(new RuntimeException('Connection refused [tcp://redis:6379]'));
}
