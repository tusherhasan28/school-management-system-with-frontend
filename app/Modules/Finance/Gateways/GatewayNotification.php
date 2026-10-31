<?php

namespace App\Modules\Finance\Gateways;

/**
 * A verified statement from the provider about one payment.
 * status: successful | failed | pending.
 */
final class GatewayNotification
{
    /** @param  array<string, mixed>  $payload  raw provider data (sanitised before storage) */
    public function __construct(
        public readonly string $publicId,
        public readonly ?string $transactionId,
        public readonly ?string $amount,
        public readonly string $status,
        public readonly array $payload = [],
    ) {}
}
