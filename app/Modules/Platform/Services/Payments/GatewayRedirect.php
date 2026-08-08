<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services\Payments;

/**
 * Where to send the customer, and the handle we must remember to verify later.
 */
final readonly class GatewayRedirect
{
    /**
     * @param  string  $authority  the gateway's id for this attempt; stored UNIQUE and
     *                             used to recognise a replayed callback
     * @param  'GET'|'POST'  $method  some Iranian PSPs require a form POST rather than a
     *                                plain redirect
     * @param  array<string, string>  $fields  hidden form fields, for the POST case
     */
    public function __construct(
        public string $authority,
        public string $url,
        public string $method = 'GET',
        public array $fields = [],
    ) {}
}
