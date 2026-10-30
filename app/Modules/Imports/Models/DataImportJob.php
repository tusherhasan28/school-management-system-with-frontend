<?php

namespace App\Modules\Imports\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $import_type
 * @property string $original_name
 * @property string $source_path
 * @property array<string, mixed>|null $options
 * @property string $status
 * @property int $total_rows
 * @property int $valid_rows
 * @property int $error_rows
 * @property int $duplicate_rows
 * @property int $imported_rows
 * @property array<int, mixed>|null $preview
 * @property string|null $error_report_path
 */
class DataImportJob extends Model
{
    use BelongsToSchool;

    protected $fillable = ['import_type', 'original_name', 'options'];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'preview' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
