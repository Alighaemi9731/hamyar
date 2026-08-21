<?php

declare(strict_types=1);

/*
| The login page's links have to RESOLVE, not merely render.
|
| «ثبت نام» was hand-built as `URL::formatScheme().config('app.domain').'/register'`,
| which was right while login was served on a shop's hostname and sign-up lived on the
| central domain. ADR 0017 put both on `app.<apex>`, and that URL went on pointing at the
| LANDING host, where /register does not exist. The page looked perfect and the link 404'd
| on click — which is exactly the fault an assertion on a substring, or on the anchor being
| present, cannot see. So these tests read the href the page actually rendered and follow it.
|
| No hostname literal below: every URL comes from the route table or from the page's own
| markup (golden rule 1b — a literal in a fixture is what makes "the apex is configurable"
| quietly untrue the day it changes).
*/

beforeEach(function (): void {
    $this->page = $this->get(route('login'));

    $this->page->assertOk();

    // Matched by its visible label rather than by the path it points at: asking "where
    // does the register link go" must not presuppose the answer.
    preg_match(
        '/<a\b[^>]*\bhref="([^"]*)"[^>]*>\s*ثبت نام\s*<\/a>/u',
        (string) $this->page->getContent(),
        $matches,
    );

    $this->registerHref = $matches[1] ?? null;
});

it('offers a way to register', function (): void {
    // Narrowed to a local before `expect()` sees it. A property set on the test case in
    // `beforeEach` is `mixed` to static analysis, and `expect(mixed)` gives Larastan no
    // TValue to resolve — level 8 fails the build on it while the test itself passes.
    /** @var string|null $registerHref */
    $registerHref = $this->registerHref;

    expect($registerHref)->not->toBeNull();
});

it('links «ثبت نام» to a page that actually resolves', function (): void {
    $this->get((string) $this->registerHref)->assertOk();
});

it('keeps sign-up on the same origin as sign-in', function (): void {
    // ADR 0017, and not cosmetic: sign-up POSTs to its own host, and a cross-origin
    // hand-over out of a form is blocked by `form-action 'self'` — the bug that broke
    // onboarding twice. One origin removes its shape instead of working around it again.
    expect(parse_url((string) $this->registerHref, PHP_URL_HOST))
        ->toBe(parse_url(route('login'), PHP_URL_HOST));
});
