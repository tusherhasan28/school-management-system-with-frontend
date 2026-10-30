<?php

namespace App\Modules\Academics\Models;

use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which subjects a class studies in a session, with full and pass marks.
 *
 * @property int $id
 * @property int $academic_session_id
 * @property int $class_id
 * @property int $subject_id
 * @property string $full_marks
 * @property string $pass_marks
 * @property bool $is_optional
 */
class ClassSubject extends Model
{
    use BelongsToSchool;

    protected $fillable = ['academic_session_id', 'class_id', 'subject_id', 'full_marks', 'pass_marks', 'is_optional', 'sort_order'];

    protected function casts(): array
    {
        return ['full_marks' => 'decimal:2', 'pass_marks' => 'decimal:2', 'is_optional' => 'boolean'];
    }

    /**
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
