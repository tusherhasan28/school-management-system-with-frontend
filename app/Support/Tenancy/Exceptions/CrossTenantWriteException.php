<?php

namespace App\Support\Tenancy\Exceptions;

use RuntimeException;

class CrossTenantWriteException extends RuntimeException
{
    public static function for(string $model, ?int $rowSchool, ?int $contextSchool): self
    {
        return new self(sprintf(
            'Refused to write %s belonging to school #%s while tenant context is school #%s.',
            class_basename($model),
            $rowSchool ?? 'null',
            $contextSchool ?? 'none',
        ));
    }
}
