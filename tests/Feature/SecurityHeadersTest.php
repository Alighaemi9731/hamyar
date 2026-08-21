<?php

declare(strict_types=1);

/**
 * The headers that decide what a browser will let a page do.
 *
 * Every other layer in this application defends the server. These defend the operator's
 * browser, which is where an attacker who gets there is *already* holding the session
 * and passing every server-side check.
 *
 * They are asserted rather than trusted because a header is invisible: nothing on any
 * screen looks different when one stops being sent, and the way they stop being sent is
 * a middleware dropped from a group during an unrelated refactor.
 */
beforeEach(function (): void {
    // One host for every shop since ADR 0017, and these headers are a property of the
    // response rather than of the shop — so there is no tenant to create here. `/login`
    // is the page they are asserted on because it is the one screen everybody reaches
    // before having a session at all.
    $this->url = appUrl();
});

it('sends the security headers on a page', function (string $header, string $expected): void {
    $this->get($this->url.'/login')->assertHeader($header, $expected);
})->with([
    'nosniff' => ['X-Content-Type-Options', 'nosniff'],
    'no framing' => ['X-Frame-Options', 'DENY'],
    'referrer' => ['Referrer-Policy', 'strict-origin-when-cross-origin'],
]);

it('sends them on an error page too', function (): void {
    // A 404 is served to a browser like any other response. Stamping only successful
    // ones is the shape of guard that looks present and covers half the surface.
    $this->get($this->url.'/no-such-page')
        ->assertNotFound()
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('refuses the things nothing in the product needs', function (string $directive): void {
    $policy = $this->get($this->url.'/login')->headers->get('Content-Security-Policy') ?? '';

    expect($policy)->toContain($directive);
})->with([
    // Clickjacking a POS screen. `frame-ancestors` is the one current browsers read.
    "frame-ancestors 'none'",
    "object-src 'none'",
    // Stops an injected `<base href>` re-pointing every relative URL, form actions
    // included, at somebody else's host.
    "base-uri 'self'",
    // A form injected into a page cannot post the shop's session anywhere but here.
    "form-action 'self'",
]);

it('permits the things the product actually does', function (string $needle): void {
    $policy = $this->get($this->url.'/login')->headers->get('Content-Security-Policy') ?? '';

    // One needle per case. `toContain()` treats a second argument as another needle,
    // not as a failure message — the first draft passed the reason in and quietly
    // asserted the policy contained an English sentence.
    expect($policy)->toContain($needle);
})->with([
    // Each key is the screen that breaks silently if its source is dropped: a
    // flattened chart or a missing barcode reads as a rendering bug, not a policy.
    'data: barcodes and QR codes on receipts and tracking links' => 'img-src \'self\' data: blob:',
    'blob: photo previews in repair intake' => 'blob:',
    'computed style attributes — chart heights, label millimetres' => "style-src 'self' 'unsafe-inline'",
]);

it('gives the inline theme script a nonce, and does not blanket-allow inline script', function (): void {
    $response = $this->get($this->url.'/login');

    $policy = $response->headers->get('Content-Security-Policy') ?? '';

    // The nonce is what lets the anti-FOUC script run. Without it the page renders
    // light and repaints dark, and the fix somebody reaches for is 'unsafe-inline'.
    expect($policy)->toMatch("/script-src [^;]*'nonce-[A-Za-z0-9+\/=]+'/")
        ->and($response->getContent())->toContain('nonce=');

    /** @var string $scriptSrc */
    $scriptSrc = collect(explode(';', $policy))
        ->map(fn (string $directive): string => trim($directive))
        ->first(fn (string $directive): bool => str_starts_with($directive, 'script-src'));

    // The whole point of the nonce is that this is absent. A policy carrying both is
    // a policy where the nonce is decoration — browsers ignore it once 'unsafe-inline'
    // is present, which is exactly how a CSP ends up permitting everything.
    expect($scriptSrc)->not->toContain("'unsafe-inline'");
});

it('does not pin a developer machine to https', function (): void {
    // HSTS over plain http is ignored by the spec; from a local https proxy it would
    // pin *.localhost for a year, which is a day of confusion for no protection.
    $this->get($this->url.'/login')->assertHeaderMissing('Strict-Transport-Security');
});

it('names every source in a form a browser will accept', function (): void {
    // A bracketed IPv6 literal is not a valid CSP source expression, and a browser
    // meeting one DISCARDS that source and keeps the rest of the directive — so the
    // policy silently narrows rather than failing. Found on the browser walk.
    $policy = $this->get($this->url.'/login')->headers->get('Content-Security-Policy') ?? '';

    expect($policy)->not->toContain('[::1]');
});
