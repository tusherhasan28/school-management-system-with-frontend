<?php

namespace App\Modules\Exams\Models;

use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string|null $name_bn
 */
class ExamType extends Model
{
    use BelongsToSchool;

    protected $fillable = ['name', 'name_bn', 'sort_order'];
}
