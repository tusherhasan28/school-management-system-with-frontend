<?php

namespace App\Modules\Admissions\Services;

use App\Modules\Academics\Models\AcademicSession;
use App\Modules\Admissions\Models\AdmissionApplication;
use App\Modules\People\Models\Student;
use App\Modules\People\Services\GuardianService;
use App\Modules\People\Services\StudentService;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\TenantSequence;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Public application → review → approve/reject → conversion into a student
 * with guardian link and enrollment (single transaction, idempotent).
 */
class AdmissionService
{
    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        'submitted' => ['under_review', 'approved', 'rejected'],
        'under_review' => ['approved', 'rejected'],
        'approved' => ['rejected'],
        'rejected' => ['under_review'],
        'enrolled' => [],
    ];

    public function __construct(
        private readonly TenantSequence $sequence,
        private readonly StudentService $students,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validated public form data
     */
    public function submit(array $data): AdmissionApplication
    {
        return DB::transaction(function () use ($data) {
            $extra = array_intersect_key($data, array_flip(['father_name', 'mother_name', 'religion', 'blood_group']));

            $application = new AdmissionApplication(array_diff_key($data, $extra));
            $application->guardian_phone = GuardianService::normalizePhone($data['guardian_phone']);
            $application->academic_session_id ??= AcademicSession::query()->where('is_current', true)->value('id');
            $application->application_no = $this->sequence->format('application_no', 'APP-{year}-{seq:5}');
            $application->application_data = $extra ?: null;
            $application->status = 'submitted';
            $application->submitted_at = now();
            $application->save();

            return $application;
        });
    }

    public function review(AdmissionApplication $application, string $status, ?string $note = null): AdmissionApplication
    {
        if (! in_array($status, self::TRANSITIONS[$application->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => __('admissions.invalid_transition')]);
        }

        if ($status === 'rejected' && blank($note)) {
            throw ValidationException::withMessages(['note' => __('admissions.reason_required')]);
        }

        $old = $application->status;
        $application->forceFill([
            'status' => $status,
            'review_note' => $note,
            'reviewed_by' => Auth::guard('web')->id(),
            'reviewed_at' => now(),
        ])->save();

        $this->audit->tenant('admission.'.$status, $application, ['status' => $old], ['status' => $status]);

        return $application;
    }

    /**
     * @param  array{class_id: int, section_id?: int|null, roll_no?: string|null, academic_session_id?: int|null}  $placement
     */
    public function convert(AdmissionApplication $application, array $placement): Student
    {
        return DB::transaction(function () use ($application, $placement) {
            // Lock the row so two clicks (or two officers) cannot create two students.
            $locked = AdmissionApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'approved') {
                throw ValidationException::withMessages(['status' => __('admissions.must_be_approved')]);
            }

            $sessionId = $placement['academic_session_id'] ?? $locked->academic_session_id ?? $this->students->currentSessionId();

            if ($sessionId === null) {
                throw ValidationException::withMessages(['academic_session_id' => __('admissions.no_session')]);
            }

            $student = $this->students->create(
                [
                    'full_name' => $locked->applicant_name,
                    'name_bn' => $locked->applicant_name_bn,
                    'date_of_birth' => $locked->date_of_birth?->toDateString(),
                    'gender' => $locked->gender,
                    'present_address' => $locked->address,
                    'religion' => $locked->application_data['religion'] ?? null,
                    'blood_group' => $locked->application_data['blood_group'] ?? null,
                ],
                [[
                    'full_name' => $locked->guardian_name,
                    'phone' => $locked->guardian_phone,
                    'email' => $locked->guardian_email,
                    'address' => $locked->address,
                    'relationship' => $locked->guardian_relationship,
                    'is_primary' => true,
                ]],
                [
                    'academic_session_id' => (int) $sessionId,
                    'class_id' => (int) $placement['class_id'],
                    'section_id' => $placement['section_id'] ?? null,
                    'roll_no' => $placement['roll_no'] ?? null,
                ],
            );

            $locked->forceFill(['status' => 'enrolled', 'student_id' => $student->id])->save();
            $this->audit->tenant('admission.converted', $locked, new: ['student_id' => $student->id]);

            return $student;
        });
    }
}
