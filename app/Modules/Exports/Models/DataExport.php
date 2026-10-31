<?php

namespace App\Modules\Exports\Models;

use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $status
 * @property string|null $path
 * @property int|null $size_bytes
 * @property array<string, int>|null $counts
 * @property Carbon|null $expires_at
 */
class DataExport extends Model
{
    use BelongsToSchool;

    protected $fillable = ['requested_by'];

    protected $attributes = ['status' => 'queued'];

    protected function casts(): array
    {
        return ['counts' => 'array', 'expires_at' => 'datetime'];
    }

    public function isDownloadable(): bool
    {
        return $this->status === 'ready' && $this->path !== null && $this->expires_at?->isFuture();
    }
}
