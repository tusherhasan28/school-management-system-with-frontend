<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hostname → school mapping. Central table, used by ResolveTenant.
 *
 * @property int $id
 * @property int $school_id
 * @property string $domain
 * @property bool $is_primary
 */
class SchoolDomain extends Model
{
    protected $fillable = ['domain', 'is_primary'];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<School, $this>
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public static function normalize(string $host): string
    {
        return rtrim(strtolower(trim($host)), '.');
    }
}
