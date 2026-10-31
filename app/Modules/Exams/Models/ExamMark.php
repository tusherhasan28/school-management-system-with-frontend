<?php

namespace App\Modules\Exams\Models;

use App\Modules\People\Models\Student;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A student's marks in one exam subject.
 * status: present (parts entered), absent, exempt (does not take this subject,
 * e.g. the other religion paper or an optional subject they did not choose).
 *
 * @property int $id
 * @property int $exam_subject_id
 * @property int $exam_mark_sheet_id
 * @property int $student_id
 * @property string $status
 * @property string|null $total
 * @property string|null $remarks
 */
class ExamMark extends Model
{
    use BelongsToSchool;

    public const STATUSES = ['present', 'absent', 'exempt'];

    protected $fillable = ['exam_subject_id', 'exam_mark_sheet_id', 'student_id', 'status', 'total', 'remarks', 'entered_by'];

    /** @return HasMany<ExamMarkPart, $this> */
    public function parts(): HasMany
    {
        return $this->hasMany(ExamMarkPart::class);
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
