<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    /**
     * Laravel 12 no longer includes this in the skeleton's base controller, but every
     * resource in this product is policy-guarded (CLAUDE.md conventions), so
     * `$this->authorize(...)` has to exist on the base class — otherwise each module
     * either re-adds the trait or, worse, silently skips the check.
     */
    use AuthorizesRequests;
}
