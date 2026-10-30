<?php

namespace App\Modules\Homework\Models;

use App\Modules\People\Models\Student;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $homework_id
 * @property int $student_id
 * @property string $status
 * @property string|null $marks
 * @property string|null $feedback
 */
class HomeworkSubmission extends Model
{
    use BelongsToSchool;

    protected $fillable = ['homework_id', 'student_id', 'status', 'submitted_at', 'submission_text', 'marks', 'feedback'];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'graded_at' => 'datetime', 'marks' => 'decimal:2'];
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
