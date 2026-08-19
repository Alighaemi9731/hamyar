<?php

declare(strict_types=1);

use App\Modules\Platform\Models\Tenant;
use App\Support\Audit\Redactor;
use App\Support\Observability\ScrubSensitiveData;
use App\Support\SensitiveInput;
use App\Support\Tenancy\TenantContext;
use Sentry\Event;

/**
 * The second door.
 *
 * `PasscodeSecurityTest` already proves a failed repair intake does not flash the
 * customer's unlock code into the session. This file proves the same value does not
 * leave the building through the crash reporter, which is the same hole in a different
 * wall and was open until Phase 11.3.
 *
 * The tests are written against `ScrubSensitiveData` directly rather than by provoking
 * a real transmission: a test that needs a DSN is a test that only runs where somebody
 * configured one, which is nowhere.
 */
afterEach(function (): void {
    app(TenantContext::class)->forget();
});

/**
 * Run the scrubber and hand back one section of the request, typed.
 *
 * `Event::getRequest()` is declared `array` with no shape, so every offset read off it
 * is `mixed` and Larastan level 8 rejects thirteen of them. Narrowing once here beats
 * thirteen casts, and the assertion is real: a section that came back as something
 * other than an array means the scrubber replaced it rather than walking it.
 *
 * @param  array<array-key, mixed>  $request
 * @return array<array-key, mixed>
 */
function scrubbed(array $request, string $section = 'data'): array
{
    $event = Event::createEvent();
    $event->setRequest($request);

    $result = ScrubSensitiveData::handle($event)->getRequest()[$section] ?? null;

    expect($result)->toBeArray("the scrubber did not return an array for [{$section}]");

    /** @var array<array-key, mixed> $result */
    return $result;
}

it('masks every key the session door refuses to flash', function (): void {
    /*
    | The wiring assertion, and the reason `SensitiveInput` exists as a class instead of
    | two lists in two files. Adding a key for one door must close the other.
    |
    | Iterated rather than spot-checked: a spot check passes forever after somebody adds
    | the fourteenth key and updates only the list they were looking at.
    */
    $body = [];

    foreach (SensitiveInput::keys() as $key) {
        $body[$key] = 'the-actual-secret';
    }

    $scrubbed = scrubbed(['data' => $body]);

    foreach (SensitiveInput::keys() as $key) {
        expect($scrubbed[$key])->toBe(Redactor::MASK, "[{$key}] reached the crash report in clear.");
    }
});

it('masks a secret nested inside a request body', function (): void {
    /*
    | A repair intake posts `lines[0][device_passcode]`. A scrubber that reads only the
    | top level reports itself as having scrubbed the request and ships the value one
    | bracket down — which looks identical in a diff and in a green test.
    */
    $scrubbed = scrubbed(['data' => [
        'customer' => 'رضا',
        'lines' => [
            ['device_brand' => 'اپل', 'device_passcode' => '1234'],
        ],
    ]]);

    /** @var array<array-key, array<string, mixed>> $lines */
    $lines = $scrubbed['lines'];

    expect($lines[0]['device_passcode'])->toBe(Redactor::MASK)
        // The other half: over-masking is safe, masking everything is useless.
        ->and($lines[0]['device_brand'])->toBe('اپل')
        ->and($scrubbed['customer'])->toBe('رضا');
});

it('masks a credential nobody put on the list, by its name', function (string $key): void {
    // The widened door. An integration credential added next year is caught the day it
    // is introduced rather than the day somebody remembers this file.
    expect(scrubbed(['data' => [$key => 'value']])[$key])->toBe(Redactor::MASK);
})->with([
    'kavenegar_api_key',
    'MOADIAN_PRIVATE_KEY',
    'Authorization',
    'zarinpal_secret',
    'new_password',
]);

it('scrubs the query string and headers too, not just the body', function (): void {
    // An OTP arrives as often in a URL as in a body, and `Authorization` only ever
    // arrives as a header.
    $request = [
        'query_string' => ['token' => 'bearer-value', 'page' => '2'],
        'headers' => ['Authorization' => 'Bearer abc', 'Accept' => 'application/json'],
    ];

    $query = scrubbed($request, 'query_string');
    $headers = scrubbed($request, 'headers');

    expect($query['token'])->toBe(Redactor::MASK)
        ->and($query['page'])->toBe('2')
        ->and($headers['Authorization'])->toBe(Redactor::MASK)
        ->and($headers['Accept'])->toBe('application/json');
});

it('tags the event with the shop, and with the shop only', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'mobile-karim']);

    $event = app(TenantContext::class)->runFor(
        $tenant,
        fn (): Event => ScrubSensitiveData::handle(Event::createEvent()),
    );

    /*
    | Which shop is the question an incident actually asks, and it is not answerable
    | from a stack trace. The slug is the subdomain every one of that shop's customers
    | already types — public by construction, and readable by a human at 3am, which an
    | integer is not.
    */
    expect($event->getTags())->toMatchArray([
        'tenant_id' => (string) $tenant->id,
        'tenant' => 'mobile-karim',
    ]);
});

it('says platform rather than nothing when no shop is pinned', function (): void {
    app(TenantContext::class)->forget();

    $event = ScrubSensitiveData::handle(Event::createEvent());

    // Explicit, because an absent tag is indistinguishable from a scrubber that threw.
    expect($event->getTags()['tenant'] ?? null)->toBe('platform');
});

it('returns the event even when there is nothing to scrub', function (): void {
    /*
    | The failure mode that costs the most: `before_send` returning null discards the
    | report, and a scrubber that throws does the same. The one moment this code runs is
    | the moment something is already wrong, so every path has to hand the event back.
    */
    $event = ScrubSensitiveData::handle(Event::createEvent());

    expect($event)->toBeInstanceOf(Event::class);
});

it('is the callback Sentry is actually configured to call', function (): void {
    /*
    | Everything above tests a class nothing has to use. This is what says the product
    | uses it — on both hooks, because a transaction carries the same request payload
    | and wiring only one of the two reads in a diff as though it covers both.
    */
    expect(config('sentry.before_send'))->toBe([ScrubSensitiveData::class, 'handle'])
        ->and(config('sentry.before_send_transaction'))->toBe([ScrubSensitiveData::class, 'handle']);
});

it('never sends personal data or query bindings by default', function (): void {
    /*
    | Three settings carry the whole disclosure decision, and all three are hardcoded in
    | `config/sentry.php` rather than env-driven — an environment variable is an
    | invitation to flip one on mid-incident. This asserts they are still off.
    |
    | `sql_bindings` twice, deliberately: breadcrumbs and traces are separate switches
    | for the same values, and turning off the one you were thinking about leaves the
    | other shipping `where national_id = ?` with the national id attached.
    */
    expect(config('sentry.send_default_pii'))->toBeFalse()
        ->and(config('sentry.breadcrumbs.sql_bindings'))->toBeFalse()
        ->and(config('sentry.tracing.sql_bindings'))->toBeFalse();
});
