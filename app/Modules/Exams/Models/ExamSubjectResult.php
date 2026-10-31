<?php

namespace App\Modules\Exams\Models;

use App\Modules\Academics\Models\Subject;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $exam_result_id
 * @property int $exam_subject_id
 * @property int $subject_id
 * @property string $status
 * @property string $counted_as
 * @property string $full_marks
 * @property string $obtained_marks
 * @property string $percentage
 * @property string $grade
 * @property string $grade_point
 * @property bool $passed
 * @property list<array<string, mixed>> $components
 */
class ExamSubjectResult extends Model
{
    use BelongsToSchool;

    protected $fillable = ['exam_result_id', 'exam_subject_id', 'subject_id', 'status', 'counted_as', 'full_marks', 'obtained_marks', 'percentage', 'grade', 'grade_point', 'passed', 'components'];

    protected function casts(): array
    {
        return ['passed' => 'boolean', 'components' => 'array'];
    }

    /** @return BelongsTo<Subject, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
