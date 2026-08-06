<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test case bindings
|--------------------------------------------------------------------------
|
| Feature tests (app-level and module-owned) get the full application and a
| refreshed database.
|
| Unit tests get the application but NOT the database: the money and date helpers
| read configuration (display timezone, currency unit), so they need a container —
| but nothing in tests/Unit is allowed to touch a table. Keeping RefreshDatabase off
| here is what keeps the unit suite sub-second.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', '../app/Modules');

pest()->extend(TestCase::class)->in('Unit');

// Arch tests assert on the module layout on disk and on provider discovery, both of
// which resolve paths through the container.
pest()->extend(TestCase::class)->in('Arch');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

/**
 * Golden rule 2: money is an integer number of rial. A float here means someone
 * introduced arithmetic that will eventually be off by a rial on a tax invoice.
 */
expect()->extend('toBeRial', function () {
    expect($this->value)->toBeInt();

    return $this;
});

/**
 * Golden rule 5: timestamps cross the wire and land in the database as UTC.
 */
expect()->extend('toBeUtc', function () {
    expect($this->value->timezone->getName())->toBe('UTC');

    return $this;
});

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Marks a test as part of the cross-tenant isolation suite (`composer test:isolation`).
 *
 * Golden rule 8: every tenant-scoped endpoint needs one of these. Grouping them lets
 * the suite run on its own, which is what the deploy checklist gates on.
 */
function isolation(): void
{
    test()->group('isolation');
}
