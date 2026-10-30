<?php

namespace App\Modules\Attendance\Models;

use App\Models\User;
use App\Modules\Academics\Models\SchoolClass;
use App\Modules\Academics\Models\Section;
use App\Modules\Teaching\Models\ClassRoutine;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Attendance for one section on one date, either daily (period_key "daily")
 * or for one timetable period (period_key "routine:{id}").
 *
 * @property int $id
 * @property int $academic_session_id
 * @property int $class_id
 * @property int $section_id
 * @property \Illuminate\Support\Carbon $attendance_date
 * @property string $period_key
 * @property int|null $class_routine_id
 * @property \Illuminate\Support\Carbon|null $finalized_at
 */
class AttendanceSession extends Model
{
    use BelongsToSchool;

    protected $fillable = ['academic_session_id', 'class_id', 'section_id', 'attendance_date', 'period_key', 'class_routine_id'];

    protected function casts(): array
    {
        return ['attendance_date' => 'date', 'finalized_at' => 'datetime'];
    }

    public function isFinalized(): bool
    {
        return $this->finalized_at !== null;
    }

    /** @return HasMany<StudentAttendance, $this> */
    public function marks(): HasMany
    {
        return $this->hasMany(StudentAttendance::class);
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

    /** @return BelongsTo<ClassRoutine, $this> */
    public function routine(): BelongsTo
    {
        return $this->belongsTo(ClassRoutine::class, 'class_routine_id');
    }

    /** @return BelongsTo<User, $this> */
    public function taker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'taken_by');
    }
}
