<?php

namespace App\Modules\Exams\Models;

use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * One assessed part of an exam subject (Written, MCQ, Practical, CA, ...).
 *
 * @property int $id
 * @property int $exam_subject_id
 * @property string $name
 * @property string|null $name_bn
 * @property string $full_marks
 * @property string|null $pass_marks
 * @property int $sort_order
 */
class ExamSubjectComponent extends Model
{
    use BelongsToSchool;

    protected $fillable = ['exam_subject_id', 'name', 'name_bn', 'full_marks', 'pass_marks', 'sort_order'];
}
