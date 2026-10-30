<?php

namespace App\Modules\Attendance\Jobs;

use App\Jobs\Middleware\EnsureTenantIsAccessible;
use App\Modules\Attendance\Models\AttendanceSession;
use App\Modules\Attendance\Models\StudentAttendance;
use App\Modules\People\Models\Guardian;
use App\Modules\Attendance\Notifications\StudentAbsent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Notification;

/**
 * After daily attendance is finalized: tell the primary guardian of each
 * absent student. Email for now; SMS joins through the provider abstraction
 * in Milestone 7. Runs inside the dispatching school (QueueTenancy).
 */
class NotifyAbsentGuardians implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public int $attendanceSessionId) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new EnsureTenantIsAccessible];
    }

    public function handle(TenantContext $tenant): void
    {
        $session = AttendanceSession::query()->find($this->attendanceSessionId);

        if ($session === null) {
            return;
        }

        $absent = StudentAttendance::query()
            ->with(['student' => fn ($q) => $q->select(['id', 'school_id', 'full_name', 'name_bn'])->with(['guardians' => fn ($q) => $q->wherePivot('is_primary', true)])])
            ->where('attendance_session_id', $session->id)
            ->where('status', 'absent')
            ->get();

        foreach ($absent as $mark) {
            /** @var Guardian|null $guardian */
            $guardian = $mark->student?->guardians->first();

            if ($guardian?->email) {
                Notification::route('mail', $guardian->email)->notify(new StudentAbsent(
                    $tenant->require()->name,
                    $mark->student->full_name,
                    $session->attendance_date->toDateString(),
                ));
            }
        }
    }
}
