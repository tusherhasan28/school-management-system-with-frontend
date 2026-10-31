<?php

namespace App\Modules\Exams\Models;

use App\Modules\Academics\Models\SchoolClass;
use App\Modules\Academics\Models\Subject;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $examination_id
 * @property int $class_id
 * @property int $subject_id
 * @property string $subject_type
 * @property \Illuminate\Support\Carbon|null $exam_date
 * @property string|null $starts_at
 * @property int|null $duration_minutes
 * @property string $full_marks
 * @property string $pass_marks
 * @property int $sort_order
 */
class ExamSubject extends Model
{
    use BelongsToSchool;

    public const TYPES = ['compulsory', 'optional', 'non_gpa'];

    protected $fillable = ['examination_id', 'class_id', 'subject_id', 'subject_type', 'exam_date', 'starts_at', 'duration_minutes', 'pass_marks', 'sort_order'];

    protected function casts(): array
    {
        return ['exam_date' => 'date'];
    }

    /** @return BelongsTo<Examination, $this> */
    public function examination(): BelongsTo
    {
        return $this->belongsTo(Examination::class);
    }

    /** @return BelongsTo<SchoolClass, $this> */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /** @return BelongsTo<Subject, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /** @return HasMany<ExamSubjectComponent, $this> */
    public function components(): HasMany
    {
        return $this->hasMany(ExamSubjectComponent::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<ExamMarkSheet, $this> */
    public function sheets(): HasMany
    {
        return $this->hasMany(ExamMarkSheet::class);
    }

    /** @return HasMany<ExamMark, $this> */
    public function marks(): HasMany
    {
        return $this->hasMany(ExamMark::class);
    }
}
