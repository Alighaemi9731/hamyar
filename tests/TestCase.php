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
        $this->withoutVite();
    }
}
