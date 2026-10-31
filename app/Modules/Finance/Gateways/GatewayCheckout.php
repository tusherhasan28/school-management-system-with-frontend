<?php

namespace App\Modules\Finance\Gateways;

/** Where to send the payer: a URL, optionally with form fields to POST. */
final class GatewayCheckout
{
    /** @param  array<string, string>  $fields */
    public function __construct(
        public readonly string $url,
        public readonly string $method = 'GET',
        public readonly array $fields = [],
    ) {}
}
