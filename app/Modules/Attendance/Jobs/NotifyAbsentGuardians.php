<?php

namespace App\Modules\Attendance\Jobs;

use App\Jobs\Middleware\EnsureTenantIsAccessible;
use App\Modules\Attendance\Models\AttendanceSession;
use App\Modules\Attendance\Models\StudentAttendance;
use App\Modules\Messaging\Services\MessageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * After daily attendance is finalized: tell the primary guardian of each
 * absent student by the channels the school chose (email and/or SMS).
 * Runs inside the dispatching school (QueueTenancy).
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

    public function handle(MessageService $messages): void
    {
        $session = AttendanceSession::query()->find($this->attendanceSessionId);

        if ($session === null) {
            return;
        }

        $date = $session->attendance_date->toDateString();
        $recipients = StudentAttendance::query()
            ->with(['student' => fn ($q) => $q->select(['id', 'school_id', 'full_name', 'name_bn'])->with(['guardians' => fn ($q) => $q->wherePivot('is_primary', true)])])
            ->where('attendance_session_id', $session->id)
            ->where('status', 'absent')
            ->get()
            ->map(fn (StudentAttendance $mark) => $mark->student ? $messages->guardianRecipient($mark->student, ['date' => $date]) : null)
            ->filter()->values()->all();

        if ($recipients !== []) {
            $messages->send('attendance.absent', $recipients, $messages->channelsFor('attendance.absent'));
        }
    }
}
