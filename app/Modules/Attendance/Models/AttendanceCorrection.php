<?php

namespace App\Modules\Attendance\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only record of a change to finalized attendance.
 *
 * @property int $id
 * @property int $student_attendance_id
 * @property string $old_status
 * @property string $new_status
 * @property string $reason
 * @property int|null $corrected_by
 */
class AttendanceCorrection extends Model
{
    use BelongsToSchool;

    public const UPDATED_AT = null;

    protected $fillable = ['student_attendance_id', 'old_status', 'new_status', 'reason'];

    protected static function booted(): void
    {
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }

    /** @return BelongsTo<User, $this> */
    public function corrector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }
}
