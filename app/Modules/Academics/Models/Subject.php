<?php

namespace App\Modules\Academics\Models;

use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string|null $name_bn
 * @property string|null $code
 * @property string $subject_type
 * @property bool $is_active
 */
class Subject extends Model
{
    use BelongsToSchool;

    protected $fillable = ['name', 'name_bn', 'code', 'subject_type', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
