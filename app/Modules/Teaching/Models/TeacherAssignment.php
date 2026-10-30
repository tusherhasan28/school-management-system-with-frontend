<?php

namespace App\Modules\Teaching\Models;

use App\Modules\Academics\Models\AcademicSession;
use App\Modules\Academics\Models\SchoolClass;
use App\Modules\Academics\Models\Section;
use App\Modules\Academics\Models\Subject;
use App\Modules\People\Models\StaffMember;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who teaches what in a session. section_id null = all sections of the class;
 * subject_id null with type class_teacher = responsible for the class/section.
 *
 * @property int $id
 * @property int $academic_session_id
 * @property int $staff_member_id
 * @property int $class_id
 * @property int|null $section_id
 * @property int|null $subject_id
 * @property string $assignment_type
 */
class TeacherAssignment extends Model
{
    use BelongsToSchool;

    protected $fillable = ['academic_session_id', 'staff_member_id', 'class_id', 'section_id', 'subject_id', 'assignment_type'];

    /** @return BelongsTo<StaffMember, $this> */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class, 'staff_member_id');
    }

    /** @return BelongsTo<SchoolClass, $this> */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /** @return BelongsTo<Section, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /** @return BelongsTo<Subject, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /** @return BelongsTo<AcademicSession, $this> */
    public function academicSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class);
    }
}
