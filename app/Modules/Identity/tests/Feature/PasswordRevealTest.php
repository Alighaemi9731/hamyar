<?php

declare(strict_types=1);

use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Support\Tenancy\TenantContext;

/**
 * The show/hide control on every password field.
 *
 * ## Why this is a test and not a look
 *
 * The owner reported on 2026-09-05 that the button did not work, and the click handler
 * was bound and firing the whole time. What was wrong was the markup around it:
 *
 * · one static open eye, so the button never showed its own state and pressing it over an
 *   empty field changed nothing on the screen;
 * · no `aria-pressed`, so the state was invisible to a screen reader as well as to a
 *   person;
 * · both buttons on the sign-up page carried the same `aria-label`, so «نمایش رمز عبور»
 *   was read twice with nothing to say which field it belonged to;
 * · and nothing anywhere tied `data-reveal` to a field that exists — a typo in that
 *   attribute produces a button that is present, focusable, and silently dead, which is
 *   the exact failure being fixed.
 *
 * None of those is visible to a feature test that only checks the page renders, and the
 * whole class of them is mechanical. This is that check.
 *
 * The browser half — that the icon actually swaps — is asserted in the same commit, but
 * not here: `tests/Browser` serves on `127.0.0.1` and these pages are behind
 * `Route::domain('app.'.config('app.domain'))`, so a browser test cannot reach them at
 * all today. See the note in the pull request.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * Every `<button data-reveal="…">` on the page, as a map of its attributes.
 *
 * A valueless attribute — `hidden` — comes back with an empty string, so `toHaveKey`
 * answers "is it there" for both shapes.
 *
 * @return list<array<string, string>>
 */
function revealButtons(string $html): array
{
    preg_match_all('/<button\b([^>]*\bdata-reveal="[^"]*"[^>]*)>/', $html, $matches);

    return array_map(static function (string $attributes): array {
        preg_match_all('/([a-z-]+)(?:="([^"]*)")?/', $attributes, $pairs, PREG_SET_ORDER);

        $map = [];

        foreach ($pairs as $pair) {
            // `$pair[2]` is absent, not empty, for a valueless attribute: PHP drops a
            // trailing group that did not participate rather than passing '' for it.
            $map[$pair[1]] = $pair[2] ?? '';
        }

        return $map;
    }, $matches[1]);
}

it('gives every password field a toggle that can report its own state', function (string $path, int $expected): void {
    $html = $this->get($this->url.$path)->assertOk()->getContent();

    expect($html)->toBeString();

    /** @var string $html */
    $buttons = revealButtons($html);

    expect($buttons)->toHaveCount($expected);

    foreach ($buttons as $button) {
        // The state, which is the whole of the reported bug: without it the CSS has
        // nothing to pick an icon from and the control looks identical either way.
        expect($button)->toHaveKey('aria-pressed')
            ->and($button['aria-pressed'])->toBe('false');

        // Both labels present in the markup, so the script never has to carry Persian.
        expect($button)->toHaveKeys(['data-label-show', 'data-label-hide']);
        expect($button['aria-label'])->toBe($button['data-label-show']);

        // `type="button"`, or the eye submits a half-typed password.
        expect($button['type'])->toBe('button');

        // The field it names exists, and it is a password field.
        $field = $button['data-reveal'];
        expect($html)->toContain('id="'.$field.'"')
            ->and($html)->toMatch('/<input[^>]*id="'.preg_quote($field, '/').'"[^>]*type="password"/');

        // And it is announced as controlling that field.
        expect($button['aria-controls'])->toBe($field);
    }
})->with([
    'the sign-in page' => ['/login', 1],
    'the sign-up page' => ['/register', 2],
]);

it('gives the two toggles on the sign-up page different names', function (): void {
    $html = $this->get($this->url.'/register')->assertOk()->getContent();

    expect($html)->toBeString();

    /** @var string $html */
    $labels = array_column(revealButtons($html), 'aria-label');

    // «رمز عبور» and «تکرار رمز عبور». One label for both fields is one field as far as
    // anybody listening is concerned.
    expect($labels)->toHaveCount(2)
        ->and(array_unique($labels))->toHaveCount(2);
});

it('ships the toggle hidden, so a control that cannot work is absent rather than dead', function (): void {
    $html = $this->get($this->url.'/login')->assertOk()->getContent();

    expect($html)->toBeString();

    /** @var string $html */
    // `landing.js` clears `hidden` once it has bound the click. Without the bundle — a
    // failed asset, a blocked script — the eye is not drawn at all, which is honest;
    // an inert eye is what a person reports as broken software.
    expect(revealButtons($html)[0])->toHaveKey('hidden');
});

it('no longer promises anything about the database under the form', function (): void {
    // Removed at the owner's instruction on 2026-09-05. A sign-in page is not where a
    // shop is sold on its architecture, and the visitor cannot check the claim from here.
    $this->get($this->url.'/login')->assertOk()->assertDontSee('ارتباط رمزگذاری‌شده');
    $this->get($this->url.'/register')->assertOk()->assertDontSee('ارتباط رمزگذاری‌شده');
});
