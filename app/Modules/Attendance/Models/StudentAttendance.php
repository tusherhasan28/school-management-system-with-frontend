<?php

namespace App\Modules\Attendance\Models;

use App\Modules\People\Models\Student;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $attendance_session_id
 * @property int $student_id
 * @property string $status
 * @property string|null $remarks
 */
class StudentAttendance extends Model
{
    use BelongsToSchool;

    public const STATUSES = ['present', 'absent', 'late', 'leave', 'half_day'];

    protected $table = 'student_attendance';

    protected $fillable = ['attendance_session_id', 'student_id', 'status', 'remarks'];

    /** @return BelongsTo<AttendanceSession, $this> */
    public function attendanceSession(): BelongsTo
    {
        return $this->belongsTo(AttendanceSession::class);
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
