<?php

namespace App\Support\Tenancy;

use Illuminate\Auth\Passwords\DatabaseTokenRepository;
use Illuminate\Database\Query\Builder;

/**
 * Password reset tokens keyed by (school_id, email). The stock repository keys
 * by email only, which would let a token issued on one school be checked
 * against an account with the same address on another school.
 */
class TenantPasswordTokenRepository extends DatabaseTokenRepository
{
    protected function getTable(): Builder
    {
        return $this->connection->table($this->table)
            ->where('school_id', app(TenantContext::class)->require()->getKey());
    }

    /**
     * @return array<string, mixed>
     */
    protected function getPayload($email, #[\SensitiveParameter] $token): array
    {
        return ['school_id' => app(TenantContext::class)->require()->getKey()] + parent::getPayload($email, $token);
    }
}
