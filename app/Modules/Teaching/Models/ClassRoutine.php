<?php

namespace App\Modules\Teaching\Models;

use App\Modules\Academics\Models\SchoolClass;
use App\Modules\Academics\Models\Section;
use App\Modules\Academics\Models\Subject;
use App\Modules\People\Models\StaffMember;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One timetable period. day_of_week is ISO-8601 (1 = Monday … 7 = Sunday);
 * which days are school days comes from the school calendar.
 *
 * @property int $id
 * @property int $academic_session_id
 * @property int $class_id
 * @property int $section_id
 * @property int $subject_id
 * @property int|null $staff_member_id
 * @property int $day_of_week
 * @property string $starts_at
 * @property string $ends_at
 * @property string|null $room
 */
class ClassRoutine extends Model
{
    use BelongsToSchool;

    protected $fillable = ['academic_session_id', 'class_id', 'section_id', 'subject_id', 'staff_member_id', 'day_of_week', 'starts_at', 'ends_at', 'room'];

    protected function casts(): array
    {
        return ['day_of_week' => 'integer'];
    }

    /** @return BelongsTo<Subject, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

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
}
