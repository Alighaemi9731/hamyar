<?php

declare(strict_types=1);

namespace Tests;

/**
 * The base case for `tests/Browser` — a real browser, and therefore real assets.
 *
 * The only difference from {@see TestCase} is that `@vite` is left alone. A browser test
 * exists to assert what a rendered page does, and a page whose script tags were stubbed
 * out renders nothing at all: the Inertia payload arrives in `data-page`, no bundle
 * loads to read it, and the body is empty. Nothing errors — there is no JavaScript to
 * throw — so the failure surfaces as "expected to see text … but it was not found",
 * which is true and useless.
 *
 * The consequence is that these tests need `public/build` to exist. That is why the
 * browser CI job runs `npm run build` before Pest, and why the suite is excluded from
 * the default run rather than merely being slow.
 */
abstract class BrowserTestCase extends TestCase
{
    protected function shouldDisableVite(): bool
    {
        return false;
    }
}
