<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academics\Models\Section;
use App\Modules\Attendance\Models\AttendanceCorrection;
use App\Modules\Attendance\Models\AttendanceSession;
use App\Modules\Attendance\Models\StudentAttendance;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Teaching\Models\ClassRoutine;
use App\Modules\Teaching\Services\SchoolCalendar;
use App\Modules\Teaching\Services\TeachingAccess;
use App\Modules\Teaching\Services\TeachingScope;
use App\Support\Tenancy\TenantRule;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly TeachingAccess $access,
        private readonly TeachingScope $scope,
        private readonly SchoolCalendar $calendar,
    ) {}

    /**
     * Sections (and today's periods) the user can take attendance for, with status.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('attendance.mark') || $user->can('attendance.view_all'), 403);

        $date = $this->date($request);
        $full = $user->can('attendance.view_all');

        $sections = Section::query()
            ->with('schoolClass:id,school_id,name,level_order')
            ->where('is_active', true)
            ->when(! $full, fn ($q) => $this->scope->constrain($q, $user, sectionColumn: 'id'))
            ->get()
            ->sortBy([
                fn ($a, $b) => ($a->schoolClass?->level_order <=> $b->schoolClass?->level_order),
                fn ($a, $b) => strnatcasecmp($a->name, $b->name),
            ])
            ->values();

        $sessions = AttendanceSession::query()
            ->withCount(['marks', 'marks as absent_count' => fn ($q) => $q->where('status', 'absent')])
            ->whereDate('attendance_date', $date->toDateString())
            ->whereIn('section_id', $sections->pluck('id'))
            ->get()
            ->groupBy('period_key');

        $daily = $sessions->get('daily', collect())->keyBy('section_id');
        $staff = $this->scope->staffFor($user);

        $periods = $staff
            ? ClassRoutine::query()
                ->with(['subject:id,school_id,name', 'schoolClass:id,school_id,name', 'section:id,school_id,name'])
                ->where('staff_member_id', $staff->id)
                ->where('day_of_week', $date->dayOfWeekIso)
                ->whereIn('academic_session_id', $this->attendance->sessionFor($date)?->id ? [$this->attendance->sessionFor($date)->id] : [0])
                ->orderBy('starts_at')
                ->get()
                ->map(fn (ClassRoutine $r) => [
                    'id' => $r->id, 'section_id' => $r->section_id, 'starts_at' => substr($r->starts_at, 0, 5), 'ends_at' => substr($r->ends_at, 0, 5),
                    'subject' => $r->subject?->name, 'class' => $r->schoolClass?->name, 'section' => $r->section?->name,
                    'status' => $this->status($sessions->get('routine:'.$r->id)?->first()),
                ])
            : collect();

        return Inertia::render('app/attendance/index', [
            'date' => $date->toDateString(),
            'today' => $this->calendar->today()->toDateString(),
            'closed' => $this->calendar->closedReason($date),
            'sections' => $sections->map(fn (Section $s) => [
                'id' => $s->id, 'name' => $s->name, 'class' => $s->schoolClass?->name,
                'canTake' => $this->access->canTakeDaily($user, $s),
                'status' => $this->status($daily->get($s->id)),
                'marked' => $daily->get($s->id)?->marks_count ?? 0,
                'absent' => $daily->get($s->id)?->absent_count ?? 0,
            ]),
            'periods' => $periods,
        ]);
    }

    public function take(Request $request): Response
    {
        $data = $request->validate([
            'section' => ['required', 'integer'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'routine' => ['nullable', 'integer'],
        ]);

        $section = Section::query()->with('schoolClass:id,school_id,name')->findOrFail($data['section']);
        $routine = isset($data['routine']) ? ClassRoutine::query()->with('subject:id,school_id,name')->findOrFail($data['routine']) : null;
        $user = $request->user();

        abort_unless($this->access->canViewAttendance($user, $section) || ($routine && $this->access->canTakePeriod($user, $routine)), 403);

        $date = $this->date($request);
        $session = $this->attendance->find($section, $date, $routine);
        $marks = $session ? $session->marks()->get()->keyBy('student_id') : collect();
        $corrections = $session?->isFinalized()
            ? AttendanceCorrection::query()->with('corrector:id,school_id,name')->whereIn('student_attendance_id', $marks->pluck('id'))->latest('id')->get()
            : collect();

        $canTake = $routine ? $this->access->canTakePeriod($user, $routine) : $this->access->canTakeDaily($user, $section);

        return Inertia::render('app/attendance/take', [
            'section' => ['id' => $section->id, 'name' => $section->name, 'class' => $section->schoolClass?->name],
            'routine' => $routine ? ['id' => $routine->id, 'subject' => $routine->subject?->name, 'starts_at' => substr($routine->starts_at, 0, 5)] : null,
            'date' => $date->toDateString(),
            'closed' => $this->calendar->closedReason($date),
            'future' => $date->greaterThan($this->calendar->today()),
            'session' => $session ? ['id' => $session->id, 'finalized_at' => $session->finalized_at?->toIso8601String()] : null,
            'students' => $this->attendance->roster($section, $date)->map(fn ($e) => [
                'student_id' => $e->student_id,
                'full_name' => $e->student?->full_name,
                'name_bn' => $e->student?->name_bn,
                'roll_no' => $e->roll_no,
                'mark_id' => $marks->get($e->student_id)?->id,
                'status' => $marks->get($e->student_id)?->status,
                'remarks' => $marks->get($e->student_id)?->remarks,
            ]),
            'corrections' => $corrections->map(fn (AttendanceCorrection $c) => [
                'id' => $c->id, 'mark_id' => $c->student_attendance_id, 'old_status' => $c->old_status, 'new_status' => $c->new_status,
                'reason' => $c->reason, 'by' => $c->corrector?->name, 'at' => $c->created_at?->toIso8601String(),
            ]),
            'can' => [
                'take' => $canTake,
                'correct' => $this->access->canCorrect($user, $section),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'section_id' => ['required', 'integer', TenantRule::exists('sections')],
            'date' => ['required', 'date_format:Y-m-d'],
            'routine_id' => ['nullable', 'integer', TenantRule::exists('class_routines')],
            'marks' => ['required', 'array', 'min:1', 'max:300'],
            'marks.*.status' => ['required', Rule::in(StudentAttendance::STATUSES)],
            'marks.*.remarks' => ['nullable', 'string', 'max:255'],
            'finalize' => ['boolean'],
        ]);

        $section = Section::query()->findOrFail($data['section_id']);
        $routine = isset($data['routine_id']) ? ClassRoutine::query()->findOrFail($data['routine_id']) : null;
        $user = $request->user();

        abort_unless($routine ? $this->access->canTakePeriod($user, $routine) : $this->access->canTakeDaily($user, $section), 403);

        $session = $this->attendance->record($section, CarbonImmutable::parse($data['date']), $data['marks'], $routine);

        if ($request->boolean('finalize')) {
            $this->attendance->finalize($session);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __($request->boolean('finalize') ? 'attendance.finalized' : 'attendance.saved')]);

        return back();
    }

    public function correct(Request $request, StudentAttendance $mark): RedirectResponse
    {
        $session = AttendanceSession::query()->findOrFail($mark->attendance_session_id);
        $section = Section::query()->findOrFail($session->section_id);
        abort_unless($this->access->canCorrect($request->user(), $section), 403);

        $data = $request->validate([
            'status' => ['required', Rule::in(StudentAttendance::STATUSES)],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $this->attendance->correct($mark, $data['status'], $data['reason']);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('attendance.corrected')]);

        return back();
    }

    public function report(Request $request): Response
    {
        $data = $request->validate([
            'section' => ['required', 'integer'],
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        $section = Section::query()->with('schoolClass:id,school_id,name')->findOrFail($data['section']);
        abort_unless($this->access->canViewAttendance($request->user(), $section), 403);

        $month = CarbonImmutable::createFromFormat('Y-m', $data['month'] ?? $this->calendar->today()->format('Y-m'))->startOfMonth();
        $from = $month;
        $to = $month->endOfMonth()->min($this->calendar->today());
        $summary = collect($to->lessThan($from) ? [] : $this->attendance->summary($section, $from, $to))->keyBy('student_id');

        return Inertia::render('app/attendance/report', [
            'section' => ['id' => $section->id, 'name' => $section->name, 'class' => $section->schoolClass?->name],
            'month' => $month->format('Y-m'),
            'workingDays' => $to->lessThan($from) ? 0 : $this->calendar->workingDays($from, $to),
            'rows' => $this->attendance->roster($section, $to->lessThan($from) ? $from : $to)->map(fn ($e) => [
                'student_id' => $e->student_id,
                'full_name' => $e->student?->full_name,
                'roll_no' => $e->roll_no,
                ...($summary->get($e->student_id) ?? ['present' => 0, 'absent' => 0, 'late' => 0, 'leave' => 0, 'half_day' => 0, 'marked' => 0, 'rate' => null]),
            ]),
        ]);
    }

    private function date(Request $request): CarbonImmutable
    {
        $value = $request->query('date');

        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
            ? CarbonImmutable::parse($value)
            : $this->calendar->today();
    }

    private function status(?AttendanceSession $session): string
    {
        return match (true) {
            $session === null => 'not_taken',
            $session->isFinalized() => 'finalized',
            default => 'draft',
        };
    }
}
