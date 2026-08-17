<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Moadian submissions
    |--------------------------------------------------------------------------
    |
    | OFF by default and off for every plan at launch — see
    | docs/adr/0011-moadian-adapter-without-a-provider.md. No real intermediary provider
    | was chosen: the customers this launches to are mostly on presumptive taxation, and
    | picking a provider before one has been asked for means buying an integration the
    | first real request is likely to contradict.
    |
    | The deployment-wide switch exists so that no development machine can ever submit a
    | real tax document, whatever a tenant's own setting says. Both must be true.
    |
    */

    'enabled' => (bool) env('MOADIAN_ENABLED', false),

    /*
    | `fake` is the only driver that exists. When the first paying tenant asks for Moadian,
    | a provider is chosen and its driver is built against the existing contract — that is
    | the post-launch backlog item, and this key is where it gets selected.
    */
    'driver' => env('MOADIAN_DRIVER', 'fake'),

    'queue' => env('MOADIAN_QUEUE', 'moadian'),

];
