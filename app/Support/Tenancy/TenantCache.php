<?php

namespace App\Support\Tenancy;

use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Cache wrapper that namespaces every key by school. Use this for any cached
 * tenant data instead of the Cache facade.
 */
class TenantCache
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function key(string $key): string
    {
        return 'school:'.$this->tenant->require()->getKey().':'.$key;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return Cache::get($this->key($key), $default);
    }

    public function put(string $key, mixed $value, DateTimeInterface|DateInterval|int|null $ttl = null): bool
    {
        return Cache::put($this->key($key), $value, $ttl);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function remember(string $key, DateTimeInterface|DateInterval|int|null $ttl, Closure $callback): mixed
    {
        return Cache::remember($this->key($key), $ttl, $callback);
    }

    public function forget(string $key): bool
    {
        return Cache::forget($this->key($key));
    }
}
