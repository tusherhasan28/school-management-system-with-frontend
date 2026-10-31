<?php

namespace App\Modules\Messaging\Providers;

/** Installed SMS adapters, keyed by provider key (from config/messaging.php). */
class SmsProviderRegistry
{
    /** @var array<string, SmsProvider> */
    private array $providers = [];

    /** @param  list<class-string<SmsProvider>>  $classes */
    public function __construct(array $classes = [])
    {
        foreach ($classes as $class) {
            $this->register(app($class));
        }
    }

    public function register(SmsProvider $provider): void
    {
        $this->providers[$provider->key()] = $provider;
    }

    public function get(string $key): ?SmsProvider
    {
        return $this->providers[$key] ?? null;
    }

    /** @return array<string, SmsProvider> */
    public function all(): array
    {
        return $this->providers;
    }
}
