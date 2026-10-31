<?php

namespace App\Modules\Finance\Models;

use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string|null $name_bn
 * @property bool $is_active
 */
class FeeCategory extends Model
{
    use BelongsToSchool;

    protected $fillable = ['name', 'name_bn', 'description', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
