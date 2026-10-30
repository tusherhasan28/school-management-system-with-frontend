<?php

namespace App\Services\Tenancy;

use App\Enums\SchoolStatus;
use App\Models\School;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Status changes for a school. Suspension and archiving never delete data;
 * they only block access (see config/tenancy.php accessible_statuses).
 */
class SchoolLifecycle
{
    /** @var array<string, list<SchoolStatus>> */
    private const ALLOWED = [
        'pending' => [SchoolStatus::Trial, SchoolStatus::Active, SchoolStatus::Archived],
        'trial' => [SchoolStatus::Active, SchoolStatus::Suspended, SchoolStatus::Cancelled, SchoolStatus::Archived],
        'active' => [SchoolStatus::Suspended, SchoolStatus::Cancelled, SchoolStatus::Archived],
        'suspended' => [SchoolStatus::Active, SchoolStatus::Cancelled, SchoolStatus::Archived],
        'cancelled' => [SchoolStatus::Active, SchoolStatus::Archived],
        'archived' => [SchoolStatus::Active],
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function transition(School $school, SchoolStatus $to, ?string $reason = null): School
    {
        $from = $school->status;

        if ($from === $to) {
            return $school;
        }

        if (! in_array($to, self::ALLOWED[$from->value], true)) {
            throw ValidationException::withMessages([
                'status' => __('school.invalid_transition', ['from' => $from->label(), 'to' => $to->label()]),
            ]);
        }

        return DB::transaction(function () use ($school, $from, $to, $reason) {
            $school->status = $to;

            if ($to === SchoolStatus::Suspended) {
                $school->suspended_at = now();
                $school->suspension_reason = $reason;
            } elseif ($to === SchoolStatus::Active) {
                $school->suspended_at = null;
                $school->suspension_reason = null;
                $school->activated_at ??= now();
            }

            $school->save();

            $this->audit->platform('school.status_changed', $school, $school->id, [
                'from' => $from->value,
                'to' => $to->value,
                'reason' => $reason,
            ]);

            return $school;
        });
    }
}
