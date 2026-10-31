<?php

namespace App\Modules\Exams\Models;

use App\Modules\Academics\Models\Section;
use App\Modules\People\Models\Student;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $result_calculation_id
 * @property int $examination_id
 * @property int $student_id
 * @property int $class_id
 * @property int|null $section_id
 * @property string $full_marks
 * @property string $obtained_marks
 * @property string $percentage
 * @property string|null $gpa
 * @property string|null $gpa_without_optional
 * @property string|null $letter_grade
 * @property string $result_status
 * @property int $failed_subjects
 * @property int|null $class_position
 * @property int|null $section_position
 */
class ExamResult extends Model
{
    use BelongsToSchool;

    protected $fillable = [
        'result_calculation_id', 'examination_id', 'student_id', 'class_id', 'section_id', 'full_marks', 'obtained_marks',
        'percentage', 'gpa', 'gpa_without_optional', 'letter_grade', 'result_status', 'failed_subjects', 'class_position', 'section_position',
    ];

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<Section, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /** @return BelongsTo<Examination, $this> */
    public function examination(): BelongsTo
    {
        return $this->belongsTo(Examination::class);
    }

    /** @return HasMany<ExamSubjectResult, $this> */
    public function subjectResults(): HasMany
    {
        return $this->hasMany(ExamSubjectResult::class);
    }
}
