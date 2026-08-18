<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // `@vite` reads public/build/manifest.json, a gitignored build artefact. It
        // exists on a machine that has run `npm run build` and nowhere else, so
        // without this the suite is green locally and 500s in CI — which is exactly
        // how it failed on the first CI run.
        //
        // Feature tests assert the server's response, not the asset pipeline; the
        // build is covered by its own CI job and by the browser checks.
        if ($this->shouldDisableVite()) {
            $this->withoutVite();
        }
    }

    /**
     * Whether `@vite` should be stubbed out for this test.
     *
     * True everywhere except browser tests, which are the one kind that needs the real
     * thing: `withoutVite()` makes the directive emit nothing, so the page arrives with
     * its Inertia payload and **no script tag to consume it** — a blank white body, no
     * JavaScript error, and a failure message about text not being visible that says
     * nothing about why. {@see BrowserTestCase}
     */
    protected function shouldDisableVite(): bool
    {
        return true;
    }
}
