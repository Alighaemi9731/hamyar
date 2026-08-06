<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Server Side Rendering
    |--------------------------------------------------------------------------
    |
    | Off by default. The tenant panel is behind a login and gains little from it;
    | the public pages that DO need it (repair tracking, storefront, reseller price
    | lists, the landing page) turn it on in their phase.
    |
    */

    'ssr' => [
        'enabled' => false,
        'url' => 'http://127.0.0.1:13714',
    ],

    /*
    |--------------------------------------------------------------------------
    | Testing
    |--------------------------------------------------------------------------
    |
    | `assertInertia(...)->component('x')` checks that the page file actually exists,
    | which catches a typo'd component name before a user does.
    |
    | Two things must be right here, and both bit us:
    |
    | 1. The path is lowercase `js/pages`, not Inertia's default `js/Pages`. macOS is
    |    case-insensitive so the default resolved fine locally and failed on Linux CI.
    | 2. Module-owned pages live in app/Modules/<Name>/resources/js/pages (ADR 0003),
    |    so they are globbed in — otherwise every module page would have to be
    |    exempted from the existence check.
    |
    */

    'testing' => [

        'ensure_pages_exist' => true,

        'page_paths' => array_merge(
            [resource_path('js/pages')],
            glob(base_path('app/Modules/*/resources/js/pages')) ?: [],
        ),

        'page_extensions' => ['tsx', 'ts'],

    ],

];
