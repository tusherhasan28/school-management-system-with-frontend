<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Central audit trail for platform staff actions. Append-only.
 */
class PlatformActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['platform_user_id', 'school_id', 'action', 'subject_type', 'subject_id', 'ip_address', 'user_agent', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => false);
    }
}
