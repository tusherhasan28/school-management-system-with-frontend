<?php

namespace App\Modules\Portal\Services;

use App\Modules\Academics\Services\AcademicOptions;
use App\Modules\Attendance\Models\StudentAttendance;
use App\Modules\Exams\Models\ExamResult;
use App\Modules\Finance\Models\FeeInvoice;
use App\Modules\Finance\Models\FeePayment;
use App\Modules\Finance\Services\FinancePresenter;
use App\Modules\Finance\Services\OnlinePaymentService;
use App\Modules\Homework\Models\Homework;
use App\Modules\Homework\Models\HomeworkSubmission;
use App\Modules\Notices\Models\Event;
use App\Modules\Notices\Models\Notice;
use App\Modules\People\Models\Student;
use App\Modules\People\Models\StudentEnrollment;
use App\Modules\Teaching\Models\ClassRoutine;
use App\Modules\Teaching\Services\SchoolCalendar;
use App\Modules\Website\Services\Markdown;
use App\Support\Localization\Localized;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Read-only data for the parent and student portals, always for one student
 * whose access the caller has already checked (PortalAccess). Only fields a
 * family needs are returned: no internal ids of other students, no staff
 * notes, no other students' marks (positions are shown, names are not).
 */
class PortalData
{
    public function __construct(
        private readonly AcademicOptions $options,
        private readonly SchoolCalendar $calendar,
        private readonly FinancePresenter $present,
        private readonly OnlinePaymentService $online,
        private readonly Markdown $markdown,
    ) {}

    /** The enrollment of the current session, else the most recent one. */
    public function enrollment(Student $student): ?StudentEnrollment
    {
        $sessionId = $this->options->currentSessionId();

        return $student->enrollments()
            ->with(['schoolClass:id,school_id,name,name_bn', 'section:id,school_id,name', 'academicSession:id,school_id,name'])
            ->orderByRaw('academic_session_id = ? DESC', [$sessionId ?? 0])
            ->orderByRaw("status = 'enrolled' DESC")
            ->latest('id')
            ->first();
    }

    /** @return array<string, mixed> */
    public function profile(Student $student): array
    {
        $e = $this->enrollment($student);

        return [
            'id' => $student->id, 'name' => $student->full_name, 'name_bn' => $student->name_bn, 'admission_no' => $student->admission_no,
            'status' => $student->status,
            'class' => $e ? Localized::name($e->schoolClass?->name, $e->schoolClass?->name_bn) : null,
            'section' => $e?->section?->name, 'roll' => $e?->roll_no, 'session' => $e?->academicSession?->name,
        ];
    }

    /** @return array<string, mixed> */
    public function overview(Student $student, string $audience): array
    {
        $today = $this->calendar->today();
        $month = $this->attendanceCounts($student, $today->startOfMonth(), $today);
        $todayMark = $this->marks($student, $today, $today)->first();
        $homework = $this->homework($student);
        $fees = $this->feeTotals($student);
        $result = collect($this->results($student))->first();

        return [
            'attendance' => ['month' => $month, 'today' => $todayMark['status'] ?? null, 'closed' => $this->calendar->closedReason($today)],
            'homework' => [
                'pending' => collect($homework)->whereIn('submission', [null, 'pending'])->where('overdue', false)->count(),
                'next' => collect($homework)->whereIn('submission', [null, 'pending'])->where('overdue', false)->sortBy('due_at')->take(3)->values(),
            ],
            'fees' => $fees,
            'result' => $result,
            'notices' => collect($this->notices($audience))->take(3)->values(),
            'events' => $this->upcomingEvents(3),
            'today' => $this->periods($student, (int) $today->isoWeekday()),
        ];
    }

    /** @return array<string, mixed> */
    public function attendance(Student $student, ?string $month): array
    {
        $today = $this->calendar->today();
        $start = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $month) ? CarbonImmutable::parse($month.'-01', $today->timezone) : $today->startOfMonth();
        $start = $start->greaterThan($today) ? $today->startOfMonth() : $start;
        $end = $start->endOfMonth()->min($today);

        return [
            'month' => $start->format('Y-m'),
            'prev' => $start->subMonth()->format('Y-m'),
            'next' => $start->addMonth()->lessThanOrEqualTo($today) ? $start->addMonth()->format('Y-m') : null,
            'counts' => $this->attendanceCounts($student, $start, $end),
            'days' => $this->marks($student, $start, $end)->values(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function timetable(Student $student): array
    {
        return $this->periods($student, null);
    }

    public function weekend(): array
    {
        return $this->calendar->weekendDays();
    }

    /** @return list<array<string, mixed>> */
    public function homework(Student $student): array
    {
        $e = $this->enrollment($student);

        if ($e === null) {
            return [];
        }

        $now = now();
        $items = Homework::query()
            ->with(['subject:id,school_id,name,name_bn', 'teacher:id,school_id,full_name'])
            ->where('academic_session_id', $e->academic_session_id)
            ->where('class_id', $e->class_id)
            ->where(fn ($q) => $q->whereNull('section_id')->when($e->section_id, fn ($q) => $q->orWhere('section_id', $e->section_id)))
            ->whereIn('status', ['published', 'closed'])
            ->orderByDesc('assigned_on')->orderByDesc('id')
            ->limit(60)
            ->get();
        $submissions = HomeworkSubmission::query()->where('student_id', $student->id)->whereIn('homework_id', $items->pluck('id'))->get()->keyBy('homework_id');

        return $items->map(function (Homework $h) use ($submissions, $now) {
            $s = $submissions->get($h->id);

            return [
                'id' => $h->id, 'title' => $h->title, 'instructions' => $h->instructions,
                'subject' => Localized::name($h->subject?->name, $h->subject?->name_bn), 'teacher' => $h->teacher?->full_name,
                'assigned_on' => $h->assigned_on?->toDateString(), 'due_at' => $h->due_at?->toIso8601String(),
                'overdue' => $h->due_at !== null && $h->due_at->lt($now), 'status' => $h->status,
                'max_marks' => $h->max_marks, 'has_attachment' => (bool) $h->attachment_path, 'attachment_name' => $h->attachment_name,
                'submission' => $s?->status, 'marks' => $s?->status === 'graded' ? $s->marks : null, 'feedback' => $s?->status === 'graded' ? $s->feedback : null,
            ];
        })->values()->all();
    }

    /**
     * The student's results in published exams only (current calculation).
     *
     * @return list<array<string, mixed>>
     */
    public function results(Student $student): array
    {
        return ExamResult::query()
            ->join('examinations', function ($join) {
                $join->on('examinations.id', '=', 'exam_results.examination_id')
                    ->on('examinations.school_id', '=', 'exam_results.school_id')
                    ->on('examinations.current_calculation_id', '=', 'exam_results.result_calculation_id');
            })
            ->where('exam_results.student_id', $student->id)
            ->where('examinations.status', 'published')
            ->orderByDesc('examinations.published_at')
            ->get(['exam_results.*', 'examinations.name as exam_name', 'examinations.name_bn as exam_name_bn', 'examinations.published_at as exam_published_at'])
            ->map(fn (ExamResult $r) => [
                'exam_id' => $r->examination_id, 'exam' => Localized::name($r->exam_name, $r->exam_name_bn),
                'published_at' => $r->exam_published_at ? CarbonImmutable::parse($r->exam_published_at)->toIso8601String() : null,
                'gpa' => $r->gpa, 'letter_grade' => $r->letter_grade, 'percentage' => $r->percentage, 'status' => $r->result_status,
                'obtained' => $r->obtained_marks, 'full' => $r->full_marks,
                'class_position' => $r->class_position, 'section_position' => $r->section_position,
            ])
            ->values()->all();
    }

    /** The published result row for one exam, or null (drives the report card). */
    public function publishedResult(Student $student, int $examId): ?ExamResult
    {
        return ExamResult::query()
            ->join('examinations', function ($join) {
                $join->on('examinations.id', '=', 'exam_results.examination_id')
                    ->on('examinations.school_id', '=', 'exam_results.school_id')
                    ->on('examinations.current_calculation_id', '=', 'exam_results.result_calculation_id');
            })
            ->where('exam_results.student_id', $student->id)
            ->where('exam_results.examination_id', $examId)
            ->where('examinations.status', 'published')
            ->first(['exam_results.*']);
    }

    /** @return array<string, mixed> */
    public function fees(Student $student): array
    {
        $today = $this->calendar->today()->toDateString();
        $invoices = FeeInvoice::query()->where('student_id', $student->id)->where('status', '!=', 'cancelled')
            ->orderByDesc('issue_date')->orderByDesc('id')->limit(100)->get();
        $payments = FeePayment::query()->with('reversal')->where('student_id', $student->id)->where('status', 'successful')
            ->orderByDesc('received_at')->orderByDesc('id')->limit(100)->get();

        return [
            'totals' => $this->feeTotals($student),
            'invoices' => $invoices->map(fn (FeeInvoice $i) => collect($this->present->invoice($i, $today))->only(['id', 'invoice_no', 'billing_period', 'issue_date', 'due_date', 'total', 'paid_total', 'balance', 'status', 'overdue']))->values(),
            'payments' => $payments->map(fn (FeePayment $p) => collect($this->present->payment($p))->only(['id', 'receipt_no', 'method', 'gateway', 'amount', 'received_at', 'reversal']))->values(),
            'gateways' => $this->online->available(),
        ];
    }

    /** @return array{due: string, overdue: string, open: int} */
    public function feeTotals(Student $student): array
    {
        $today = $this->calendar->today()->toDateString();
        $open = FeeInvoice::query()->where('student_id', $student->id)->open()->get();

        return [
            'due' => Money::format((int) $open->sum(fn (FeeInvoice $i) => $i->balance())),
            'overdue' => Money::format((int) $open->filter(fn (FeeInvoice $i) => $i->isOverdue($today))->sum(fn (FeeInvoice $i) => $i->balance())),
            'open' => $open->count(),
        ];
    }

    /**
     * Live notices for the audience ("parents" or "students"), plus public ones.
     *
     * @return list<array<string, mixed>>
     */
    public function notices(string $audience, int $limit = 30): array
    {
        $tz = $this->calendar->today()->getTimezone();

        return Notice::query()->visibleTo([$audience])->newestFirst()->limit($limit)->get()
            ->map(fn (Notice $n) => [
                'id' => $n->id, 'title' => $n->local('title'), 'body' => $this->markdown->render($n->local('body') ?? ''),
                'is_pinned' => $n->is_pinned, 'published_on' => $n->publishedOn()->copy()->setTimezone($tz)->toDateString(),
                'has_attachment' => (bool) $n->attachment_path, 'attachment_name' => $n->attachment_name,
            ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    public function upcomingEvents(int $limit = 5): array
    {
        $today = $this->calendar->today()->toDateString();

        return Event::query()->where('is_public', true)
            ->where(fn ($q) => $q->where('starts_on', '>=', $today)->orWhere('ends_on', '>=', $today))
            ->orderBy('starts_on')->limit($limit)->get()
            ->map(fn (Event $e) => ['id' => $e->id, 'title' => $e->local('title'), 'starts_on' => $e->starts_on?->toDateString(), 'ends_on' => $e->ends_on?->toDateString(), 'location' => $e->location])
            ->values()->all();
    }

    /** @return array<string, int> */
    private function attendanceCounts(Student $student, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $counts = array_fill_keys(['present', 'absent', 'late', 'leave', 'half_day'], 0);

        foreach ($this->marks($student, $from, $to) as $mark) {
            $counts[$mark['status']]++;
        }

        return $counts;
    }

    /** @return Collection<int, array{date: string, status: string, remarks: ?string}> */
    private function marks(Student $student, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return StudentAttendance::query()
            ->join('attendance_sessions', function ($join) {
                $join->on('attendance_sessions.id', '=', 'student_attendance.attendance_session_id')
                    ->on('attendance_sessions.school_id', '=', 'student_attendance.school_id');
            })
            ->where('student_attendance.student_id', $student->id)
            ->where('attendance_sessions.period_key', 'daily')
            ->whereBetween('attendance_sessions.attendance_date', [$from->toDateString(), $to->toDateString()])
            ->orderByDesc('attendance_sessions.attendance_date')
            ->get(['attendance_sessions.attendance_date', 'student_attendance.status', 'student_attendance.remarks'])
            ->map(fn ($m) => ['date' => CarbonImmutable::parse($m->attendance_date)->toDateString(), 'status' => $m->status, 'remarks' => $m->remarks]);
    }

    /** @return list<array<string, mixed>> */
    private function periods(Student $student, ?int $day): array
    {
        $e = $this->enrollment($student);

        if ($e === null || $e->section_id === null) {
            return [];
        }

        return ClassRoutine::query()
            ->with(['subject:id,school_id,name,name_bn', 'teacher:id,school_id,full_name'])
            ->where('academic_session_id', $e->academic_session_id)
            ->where('section_id', $e->section_id)
            ->when($day, fn ($q) => $q->where('day_of_week', $day))
            ->orderBy('day_of_week')->orderBy('starts_at')
            ->get()
            ->map(fn (ClassRoutine $r) => [
                'id' => $r->id, 'day_of_week' => $r->day_of_week,
                'starts_at' => substr($r->starts_at, 0, 5), 'ends_at' => substr($r->ends_at, 0, 5),
                'subject' => Localized::name($r->subject?->name, $r->subject?->name_bn), 'teacher' => $r->teacher?->full_name,
                'room' => $r->room,
            ])
            ->values()->all();
    }
}
