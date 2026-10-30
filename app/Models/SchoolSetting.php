<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $setting_key
 * @property mixed $setting_value
 */
class SchoolSetting extends Model
{
    use BelongsToSchool;

    protected $fillable = ['setting_key', 'setting_value'];

    protected function casts(): array
    {
        return ['setting_value' => 'json'];
    }
}
