<?php

namespace App\Support\Tenancy\Exceptions;

use RuntimeException;

class TenantContextException extends RuntimeException
{
    public static function missing(): self
    {
        return new self('No tenant context is set for this operation.');
    }

    public static function alreadySet(int $current, int $attempted): self
    {
        return new self("Tenant context is immutable: already set to school #{$current}, refused #{$attempted}.");
    }
}
