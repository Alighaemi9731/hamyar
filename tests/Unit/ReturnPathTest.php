<?php

declare(strict_types=1);

use App\Modules\Platform\Support\ReturnPath;

/**
 * The open-redirect allow-list, tested from the attacker's side first.
 *
 * This value comes out of a query string, travels through a payment gateway, and comes
 * back to be handed to `redirect()`. The rejection cases matter more than the acceptance
 * ones: a path we wrongly reject costs a shopkeeper one click, and a host we wrongly
 * accept turns our own payment-callback URL into a redirect to somebody else's login form.
 */
it('keeps a path a shopkeeper could actually have been on', function (string $path): void {
    expect(ReturnPath::sanitise($path))->toBe($path);
})->with([
    'the till' => ['/sales/pos'],
    'a query that names the screen' => ['/sales/pos?branch=3'],
    'a nested resource' => ['/repairs/tickets/1421'],
    'Persian in the query, already encoded' => ['/crm/parties?q=%D8%AD%D8%B3%D9%86'],
    'the root' => ['/'],
]);

it('refuses anything that could leave the site', function (string $hostile): void {
    // Every one of these is a real technique, and the second is the one hand-rolled
    // checks miss: it starts with a slash, so a `str_starts_with('/')` test passes it,
    // and every browser reads it as a host.
    expect(ReturnPath::sanitise($hostile))->toBeNull();
})->with([
    'a bare host' => ['evil.test/login'],
    'protocol-relative' => ['//evil.test/login'],
    'protocol-relative with backslash' => ['/\\evil.test/login'],
    'an absolute url' => ['https://evil.test/login'],
    'a scheme with no slashes' => ['javascript:alert(1)'],
    'a data url' => ['data:text/html,<script>alert(1)</script>'],
    'a tab splicing the parser' => ["/sales\t/../evil"],
    'a newline' => ["/sales\n/evil"],
    'a null byte' => ["/sales\0/evil"],
    'a backslash anywhere' => ['/sales\\pos'],
    'empty' => [''],
]);

it('refuses a path longer than the column', function (): void {
    expect(ReturnPath::sanitise('/'.str_repeat('a', ReturnPath::MAX_LENGTH)))->toBeNull()
        ->and(ReturnPath::sanitise('/'.str_repeat('a', ReturnPath::MAX_LENGTH - 1)))->not->toBeNull();
});

it('refuses anything that is not a string', function (mixed $value): void {
    expect(ReturnPath::sanitise($value))->toBeNull();
})->with([
    'null' => [null],
    'an array — what `?return_to[]=x` produces' => [['/sales/pos']],
    'an int' => [42],
    'a bool' => [true],
]);

it('drops the fragment, which the server never sees anyway', function (): void {
    expect(ReturnPath::sanitise('/sales/pos#basket'))->toBe('/sales/pos');
});
