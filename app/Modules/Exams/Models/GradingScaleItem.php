<?php

namespace App\Modules\Exams\Models;

use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $grading_scale_id
 * @property string $grade
 * @property string $min_percentage
 * @property string $max_percentage
 * @property string $grade_point
 * @property bool $is_fail
 * @property string|null $remarks
 * @property string|null $remarks_bn
 */
class GradingScaleItem extends Model
{
    use BelongsToSchool;

    protected $fillable = ['grading_scale_id', 'grade', 'min_percentage', 'max_percentage', 'grade_point', 'is_fail', 'remarks', 'remarks_bn'];

    protected function casts(): array
    {
        return ['is_fail' => 'boolean'];
    }
}
