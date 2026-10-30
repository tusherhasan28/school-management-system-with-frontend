<?php

namespace App\Modules\Teaching\Models;

use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string|null $name_bn
 * @property \Illuminate\Support\Carbon $start_date
 * @property \Illuminate\Support\Carbon $end_date
 * @property string $holiday_type
 */
class Holiday extends Model
{
    use BelongsToSchool;

    protected $fillable = ['name', 'name_bn', 'start_date', 'end_date', 'holiday_type'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date'];
    }
}
