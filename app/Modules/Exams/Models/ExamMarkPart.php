<?php

namespace App\Modules\Exams\Models;

use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $exam_mark_id
 * @property int $exam_subject_component_id
 * @property string $marks
 */
class ExamMarkPart extends Model
{
    use BelongsToSchool;

    protected $fillable = ['exam_mark_id', 'exam_subject_component_id', 'marks'];
}
