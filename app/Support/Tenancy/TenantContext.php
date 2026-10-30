<?php

namespace App\Support\Tenancy;

use App\Models\School;
use App\Support\Tenancy\Exceptions\TenantContextException;
use Closure;
use Spatie\Permission\PermissionRegistrar;

/**
 * Request/job scoped holder of the current school.
 *
 * The school can be set once per lifecycle. Switching is only possible through
 * run(), which always restores the previous state. School identity must come
 * from the resolved hostname (requests) or the job payload (queues), never from
 * user input.
 */
class TenantContext
{
    private ?School $school = null;

    public function __construct(private readonly PermissionRegistrar $permissions) {}

    public function set(School $school): void
    {
        if ($this->school !== null && $this->school->getKey() !== $school->getKey()) {
            throw TenantContextException::alreadySet($this->school->getKey(), $school->getKey());
        }

        $this->school = $school;
        $this->permissions->setPermissionsTeamId($school->getKey());
    }

    public function clear(): void
    {
        $this->school = null;
        $this->permissions->setPermissionsTeamId(null);
    }

    public function check(): bool
    {
        return $this->school !== null;
    }

    public function school(): ?School
    {
        return $this->school;
    }

    public function id(): ?int
    {
        return $this->school?->getKey();
    }

    /**
     * The current school or an exception. Use in code paths that must never run
     * outside a tenant (services, policies, exports).
     */
    public function require(): School
    {
        return $this->school ?? throw TenantContextException::missing();
    }

    /**
     * Run a callback inside a school's context and restore the previous state.
     *
     * @template T
     *
     * @param  Closure(School): T  $callback
     * @return T
     */
    public function run(School $school, Closure $callback): mixed
    {
        $previous = $this->school;

        $this->school = null;
        $this->set($school);

        try {
            return $callback($school);
        } finally {
            $this->school = null;
            $previous ? $this->set($previous) : $this->clear();
        }
    }

    /**
     * Run a callback with no tenant context (platform code). Tenant models fail
     * closed inside, so callers must opt out of the scope explicitly.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runCentral(Closure $callback): mixed
    {
        $previous = $this->school;
        $this->clear();

        try {
            return $callback();
        } finally {
            $previous ? $this->set($previous) : $this->clear();
        }
    }
}
