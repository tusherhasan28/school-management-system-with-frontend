<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Academics\Models\AcademicSession;
use App\Modules\Academics\Models\Section;
use App\Modules\Attendance\Jobs\NotifyAbsentGuardians;
use App\Modules\Attendance\Models\AttendanceCorrection;
use App\Modules\Attendance\Models\AttendanceSession;
use App\Modules\Attendance\Models\StudentAttendance;
use App\Modules\People\Models\StudentEnrollment;
use App\Modules\Setup\Services\SchoolSettings;
use App\Modules\Teaching\Models\ClassRoutine;
use App\Modules\Teaching\Services\SchoolCalendar;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Daily or period-wise attendance for a section.
 *
 * - One attendance session per (section, date, period): database unique key,
 *   and a race-safe find-or-create here.
 * - Marks can be changed freely until the session is finalized; after that
 *   only through correct(), which records an AttendanceCorrection.
 */
class AttendanceService
{
    public function __construct(
        private readonly SchoolCalendar $calendar,
        private readonly SchoolSettings $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Students enrolled in the section on the given date, ordered by roll.
     *
     * @return Collection<int, StudentEnrollment>
     */
    public function roster(Section $section, CarbonImmutable $date): Collection
    {
        $session = $this->sessionFor($date);

        if ($session === null) {
            return collect();
        }

        return StudentEnrollment::query()
            ->with('student:id,school_id,full_name,name_bn,admission_no,status')
            ->where('academic_session_id', $session->id)
            ->where('section_id', $section->id)
            ->where('status', 'enrolled')
            ->whereDate('enrolled_at', '<=', $date->toDateString())
            ->orderByRaw('CAST(roll_no AS UNSIGNED), roll_no')
            ->get();
    }

    public function sessionFor(CarbonImmutable $date): ?AcademicSession
    {
        return AcademicSession::query()
            ->whereDate('start_date', '<=', $date->toDateString())
            ->whereDate('end_date', '>=', $date->toDateString())
            ->orderByDesc('is_current')
            ->first();
    }

    public function find(Section $section, CarbonImmutable $date, ?ClassRoutine $routine = null): ?AttendanceSession
    {
        return AttendanceSession::query()
            ->where('section_id', $section->id)
            ->whereDate('attendance_date', $date->toDateString())
            ->where('period_key', $this->periodKey($routine))
            ->first();
    }

    /**
     * Save marks (creating the attendance session on first save).
     *
     * @param  array<int|string, array{status: string, remarks?: string|null}>  $marks  keyed by student id
     */
    public function record(Section $section, CarbonImmutable $date, array $marks, ?ClassRoutine $routine = null): AttendanceSession
    {
        $this->assertCanTakeOn($section, $date, $routine);

        return DB::transaction(function () use ($section, $date, $marks, $routine) {
            $session = $this->findOrCreate($section, $date, $routine);
            $session = AttendanceSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($session->isFinalized()) {
                throw ValidationException::withMessages(['attendance' => __('attendance.already_finalized')]);
            }

            $allowed = $this->roster($section, $date)->pluck('student_id')->flip();

            foreach ($marks as $studentId => $mark) {
                if (! isset($allowed[(int) $studentId])) {
                    throw ValidationException::withMessages(['marks' => __('attendance.not_in_roster')]);
                }

                if (! in_array($mark['status'] ?? null, StudentAttendance::STATUSES, true)) {
                    throw ValidationException::withMessages(['marks' => __('attendance.invalid_status')]);
                }

                $row = StudentAttendance::query()->firstOrNew(['attendance_session_id' => $session->id, 'student_id' => (int) $studentId]);
                $row->fill(['status' => $mark['status'], 'remarks' => $mark['remarks'] ?? null]);
                $row->marked_by = Auth::guard('web')->id();
                $row->save();
            }

            $session->taken_by ??= Auth::guard('web')->id();
            $session->touch();

            return $session;
        });
    }

    /**
     * Lock the session. Every enrolled student must have a mark.
     */
    public function finalize(AttendanceSession $session): void
    {
        DB::transaction(function () use ($session) {
            $session = AttendanceSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($session->isFinalized()) {
                throw ValidationException::withMessages(['attendance' => __('attendance.already_finalized')]);
            }

            $section = Section::query()->findOrFail($session->section_id);
            $date = CarbonImmutable::parse($session->attendance_date->toDateString());
            $rosterIds = $this->roster($section, $date)->pluck('student_id');
            $marked = $session->marks()->pluck('student_id');

            if ($rosterIds->diff($marked)->isNotEmpty()) {
                throw ValidationException::withMessages(['attendance' => __('attendance.incomplete', ['count' => $rosterIds->diff($marked)->count()])]);
            }

            $session->finalized_at = now();
            $session->finalized_by = Auth::guard('web')->id();
            $session->save();

            $this->audit->tenant('attendance.finalized', $session, new: [
                'section_id' => $session->section_id,
                'date' => $session->attendance_date->toDateString(),
                'period' => $session->period_key,
                'absent' => $session->marks()->where('status', 'absent')->count(),
            ]);

            if ($session->period_key === 'daily' && $this->settings->get('attendance.notify_absent', false)) {
                NotifyAbsentGuardians::dispatch($session->id)->afterCommit();
            }
        });
    }

    /**
     * Change a finalized mark. Always leaves an AttendanceCorrection row.
     */
    public function correct(StudentAttendance $mark, string $status, string $reason): void
    {
        if (! in_array($status, StudentAttendance::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => __('attendance.invalid_status')]);
        }

        DB::transaction(function () use ($mark, $status, $reason) {
            $mark = StudentAttendance::query()->whereKey($mark->id)->lockForUpdate()->firstOrFail();
            $session = AttendanceSession::query()->findOrFail($mark->attendance_session_id);

            if (! $session->isFinalized()) {
                throw ValidationException::withMessages(['status' => __('attendance.not_finalized')]);
            }

            if ($mark->status === $status) {
                return;
            }

            $correction = new AttendanceCorrection([
                'student_attendance_id' => $mark->id,
                'old_status' => $mark->status,
                'new_status' => $status,
                'reason' => $reason,
            ]);
            $correction->corrected_by = Auth::guard('web')->id();
            $correction->save();

            $old = $mark->status;
            $mark->status = $status;
            $mark->marked_by = Auth::guard('web')->id();
            $mark->save();

            $this->audit->tenant('attendance.corrected', $mark, ['status' => $old], ['status' => $status, 'reason' => $reason]);
        });
    }

    /**
     * Per-student totals for a section over a date range (daily attendance).
     * Rate = (present + late + ½ half-day) ÷ days marked, leave excluded from
     * the denominator.
     *
     * @return list<array{student_id: int, present: int, absent: int, late: int, leave: int, half_day: int, marked: int, rate: float|null}>
     */
    public function summary(Section $section, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = StudentAttendance::query()
            ->join('attendance_sessions', function ($join) {
                $join->on('attendance_sessions.id', '=', 'student_attendance.attendance_session_id')
                    ->on('attendance_sessions.school_id', '=', 'student_attendance.school_id');
            })
            ->where('attendance_sessions.section_id', $section->id)
            ->where('attendance_sessions.period_key', 'daily')
            ->whereBetween('attendance_sessions.attendance_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('student_attendance.student_id', 'student_attendance.status')
            ->selectRaw('student_attendance.student_id, student_attendance.status, COUNT(*) as total')
            ->get();

        return $rows->groupBy('student_id')->map(function ($group, $studentId) {
            $count = fn (string $s) => (int) ($group->firstWhere('status', $s)->total ?? 0);
            $present = $count('present');
            $late = $count('late');
            $half = $count('half_day');
            $leave = $count('leave');
            $absent = $count('absent');
            $marked = $present + $late + $half + $leave + $absent;
            $denominator = $marked - $leave;

            return [
                'student_id' => (int) $studentId,
                'present' => $present, 'absent' => $absent, 'late' => $late, 'leave' => $leave, 'half_day' => $half,
                'marked' => $marked,
                'rate' => $denominator > 0 ? round(($present + $late + 0.5 * $half) / $denominator * 100, 1) : null,
            ];
        })->values()->all();
    }

    public function periodKey(?ClassRoutine $routine): string
    {
        return $routine ? 'routine:'.$routine->id : 'daily';
    }

    private function findOrCreate(Section $section, CarbonImmutable $date, ?ClassRoutine $routine): AttendanceSession
    {
        $existing = $this->find($section, $date, $routine);

        if ($existing) {
            return $existing;
        }

        $academicSession = $this->sessionFor($date) ?? throw ValidationException::withMessages(['date' => __('attendance.no_session')]);

        try {
            return AttendanceSession::create([
                'academic_session_id' => $academicSession->id,
                'class_id' => $section->class_id,
                'section_id' => $section->id,
                'attendance_date' => $date->toDateString(),
                'period_key' => $this->periodKey($routine),
                'class_routine_id' => $routine?->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another request created it a moment ago: use that one.
            return $this->find($section, $date, $routine) ?? throw ValidationException::withMessages(['attendance' => __('attendance.retry')]);
        }
    }

    private function assertCanTakeOn(Section $section, CarbonImmutable $date, ?ClassRoutine $routine): void
    {
        if ($date->greaterThan($this->calendar->today())) {
            throw ValidationException::withMessages(['date' => __('attendance.future_date')]);
        }

        if ($reason = $this->calendar->closedReason($date)) {
            throw ValidationException::withMessages(['date' => $reason]);
        }

        if ($routine !== null && ($routine->section_id !== $section->id || $routine->day_of_week !== $date->dayOfWeekIso)) {
            throw ValidationException::withMessages(['period' => __('attendance.period_mismatch')]);
        }
    }
}
