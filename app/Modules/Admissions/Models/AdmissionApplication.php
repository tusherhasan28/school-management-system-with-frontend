<?php

namespace App\Modules\Admissions\Models;

use App\Models\User;
use App\Modules\Academics\Models\AcademicSession;
use App\Modules\Academics\Models\SchoolClass;
use App\Modules\People\Models\Student;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $application_no
 * @property int|null $academic_session_id
 * @property int|null $applied_class_id
 * @property string $applicant_name
 * @property string $guardian_name
 * @property string $guardian_phone
 * @property string $status
 * @property int|null $student_id
 */
class AdmissionApplication extends Model
{
    use BelongsToSchool;

    protected $fillable = [
        'academic_session_id', 'applied_class_id', 'applicant_name', 'applicant_name_bn', 'date_of_birth', 'gender',
        'guardian_name', 'guardian_relationship', 'guardian_phone', 'guardian_email', 'address', 'previous_school',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'application_data' => 'array',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SchoolClass, $this>
     */
    public function appliedClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'applied_class_id');
    }

    /**
     * @return BelongsTo<AcademicSession, $this>
     */
    public function academicSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class);
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['submitted', 'under_review', 'approved'], true);
    }
}
