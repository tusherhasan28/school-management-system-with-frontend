<?php

namespace App\Modules\People\Models;

use App\Modules\Academics\Models\AcademicSession;
use App\Modules\Academics\Models\SchoolClass;
use App\Modules\Academics\Models\Section;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per student per academic session. Never deleted on promotion:
 * the old row is closed (status promoted/repeated/...) and a new one opened.
 *
 * @property int $id
 * @property int $student_id
 * @property int $academic_session_id
 * @property int $class_id
 * @property int|null $section_id
 * @property string|null $roll_no
 * @property string $status
 */
class StudentEnrollment extends Model
{
    use BelongsToSchool;

    protected $fillable = ['student_id', 'academic_session_id', 'class_id', 'section_id', 'roll_no', 'status', 'enrolled_at', 'ended_at', 'end_reason'];

    protected function casts(): array
    {
        return ['enrolled_at' => 'date', 'ended_at' => 'date'];
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return BelongsTo<AcademicSession, $this>
     */
    public function academicSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class);
    }

    /**
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /**
     * @return BelongsTo<Section, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }
}
