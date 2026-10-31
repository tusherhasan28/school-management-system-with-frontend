<?php

namespace App\Modules\Finance\Gateways;

/**
 * Installed gateway adapters, from config('finance.gateways').
 * Tests may register a fake adapter.
 */
class GatewayRegistry
{
    /** @var array<string, PaymentGateway> */
    private array $gateways = [];

    public function __construct()
    {
        foreach (config('finance.gateways', []) as $class) {
            $this->register(app($class));
        }
    }

    public function register(PaymentGateway $gateway): void
    {
        $this->gateways[$gateway->key()] = $gateway;
    }

    public function get(string $key): ?PaymentGateway
    {
        return $this->gateways[$key] ?? null;
    }

    /** @return array<string, PaymentGateway> */
    public function all(): array
    {
        return $this->gateways;
    }
}
