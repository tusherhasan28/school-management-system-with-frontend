<?php

namespace App\Modules\Portal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academics\Models\Section;
use App\Modules\Academics\Services\AcademicOptions;
use App\Modules\Attendance\Models\AttendanceSession;
use App\Modules\Exams\Models\ExamMarkSheet;
use App\Modules\Exams\Services\ExamAccess;
use App\Modules\Homework\Models\Homework;
use App\Modules\Homework\Models\HomeworkSubmission;
use App\Modules\Notices\Models\Notice;
use App\Modules\Teaching\Models\ClassRoutine;
use App\Modules\Teaching\Models\TeacherAssignment;
use App\Modules\Teaching\Services\SchoolCalendar;
use App\Modules\Teaching\Services\TeachingScope;
use App\Modules\Website\Services\Markdown;
use App\Support\Tenancy\TenantStorage;
use App\Support\Localization\Localized;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The teacher's day at a glance: today's periods, attendance still to take,
 * recent homework, open mark sheets, assigned classes and staff notices.
 * Everything is limited to the teacher's own assignments; the actions open
 * the existing staff screens, which check access again.
 */
class TeacherDashboardController extends Controller
{
    public function __invoke(Request $request, TeachingScope $scope, AcademicOptions $options, SchoolCalendar $calendar, ExamAccess $exams, Markdown $markdown): Response
    {
        $user = $request->user();
        $staff = $scope->staffFor($user);
        abort_if($staff === null, 403);

        $sessionId = $options->currentSessionId();
        $today = $calendar->today();

        $periods = ClassRoutine::query()
            ->with(['subject:id,school_id,name,name_bn', 'schoolClass:id,school_id,name', 'section:id,school_id,name'])
            ->where('academic_session_id', $sessionId)
            ->where('staff_member_id', $staff->id)
            ->where('day_of_week', $today->isoWeekday())
            ->orderBy('starts_at')
            ->get()
            ->map(fn (ClassRoutine $r) => [
                'id' => $r->id, 'starts_at' => substr($r->starts_at, 0, 5), 'ends_at' => substr($r->ends_at, 0, 5),
                'subject' => Localized::name($r->subject?->name, $r->subject?->name_bn), 'class' => $r->schoolClass?->name,
                'section' => $r->section?->name, 'section_id' => $r->section_id, 'room' => $r->room,
            ])->values();

        $assignments = TeacherAssignment::query()
            ->with(['schoolClass:id,school_id,name,level_order', 'section:id,school_id,name', 'subject:id,school_id,name,name_bn'])
            ->where('academic_session_id', $sessionId)
            ->where('staff_member_id', $staff->id)
            ->get()
            ->sortBy(fn (TeacherAssignment $a) => sprintf('%05d-%s-%s', $a->schoolClass?->level_order, $a->section?->name, $a->subject?->name))
            ->values();

        // Sections where this teacher is class teacher (a class-wide assignment covers every section).
        $classTeacher = $assignments->where('assignment_type', 'class_teacher');
        $sections = Section::query()
            ->with('schoolClass:id,school_id,name')
            ->where(function ($q) use ($classTeacher) {
                $q->whereRaw('1 = 0');
                foreach ($classTeacher as $a) {
                    $q->orWhere(fn ($q) => $q->where('class_id', $a->class_id)->when($a->section_id, fn ($q) => $q->where('id', $a->section_id)));
                }
            })
            ->orderBy('class_id')->orderBy('name')
            ->get();
        $taken = AttendanceSession::query()->whereIn('section_id', $sections->pluck('id'))->whereDate('attendance_date', $today->toDateString())->where('period_key', 'daily')->get()->keyBy('section_id');

        $homework = Homework::query()
            ->with(['subject:id,school_id,name,name_bn', 'schoolClass:id,school_id,name', 'section:id,school_id,name'])
            ->where('staff_member_id', $staff->id)
            ->latest('assigned_on')->latest('id')->limit(5)->get();
        $submitted = HomeworkSubmission::query()->whereIn('homework_id', $homework->pluck('id'))->whereIn('status', ['submitted', 'late', 'graded'])
            ->selectRaw('homework_id, COUNT(*) as total')->groupBy('homework_id')->pluck('total', 'homework_id');

        $sheets = ExamMarkSheet::query()
            ->with(['examSubject.examination:id,school_id,name,name_bn,status', 'examSubject.subject:id,school_id,name,name_bn', 'examSubject.schoolClass:id,school_id,name', 'section:id,school_id,name'])
            ->whereHas('examSubject.examination', fn ($q) => $q->where('status', 'marks_entry'))
            ->where('status', 'draft')
            ->get()
            ->filter(fn (ExamMarkSheet $s) => $exams->canEnter($user, $s))
            ->take(8)
            ->map(fn (ExamMarkSheet $s) => [
                'id' => $s->id, 'exam' => Localized::name($s->examSubject->examination->name, $s->examSubject->examination->name_bn),
                'subject' => Localized::name($s->examSubject->subject?->name, $s->examSubject->subject?->name_bn),
                'class' => $s->examSubject->schoolClass?->name, 'section' => $s->section?->name,
            ])->values();

        return Inertia::render('portal/teacher', [
            'teacher' => ['name' => $staff->full_name, 'designation' => $staff->designation],
            'today' => ['date' => $today->toDateString(), 'closed' => $calendar->closedReason($today)],
            'periods' => $periods,
            'attendance' => $sections->map(fn (Section $s) => [
                'section_id' => $s->id, 'class' => $s->schoolClass?->name, 'section' => $s->name,
                'state' => ($t = $taken->get($s->id)) ? ($t->isFinalized() ? 'finalized' : 'taken') : 'pending',
            ])->values(),
            'homework' => $homework->map(fn (Homework $h) => [
                'id' => $h->id, 'title' => $h->title, 'subject' => Localized::name($h->subject?->name, $h->subject?->name_bn),
                'class' => $h->schoolClass?->name, 'section' => $h->section?->name, 'due_at' => $h->due_at?->toIso8601String(),
                'status' => $h->status, 'submitted' => (int) ($submitted[$h->id] ?? 0),
            ])->values(),
            'sheets' => $sheets,
            'classes' => $assignments->map(fn (TeacherAssignment $a) => [
                'id' => $a->id, 'type' => $a->assignment_type, 'class' => $a->schoolClass?->name, 'section' => $a->section?->name,
                'subject' => $a->subject ? Localized::name($a->subject->name, $a->subject->name_bn) : null,
            ])->values(),
            'notices' => Notice::query()->visibleTo(['staff'])->newestFirst()->limit(5)->get()->map(fn (Notice $n) => [
                'id' => $n->id, 'title' => $n->local('title'), 'body' => $markdown->render($n->local('body') ?? ''),
                'is_pinned' => $n->is_pinned, 'published_on' => $n->publishedOn()->copy()->setTimezone($today->getTimezone())->toDateString(),
                'has_attachment' => (bool) $n->attachment_path, 'attachment_name' => $n->attachment_name,
            ])->values(),
            'can' => ['homework' => $user->can('homework.manage'), 'marks' => $user->can('marks.enter'), 'attendance' => $user->can('attendance.mark')],
        ]);
    }

    /** Attachment of a live staff (or public) notice. */
    public function noticeAttachment(Request $request, TenantStorage $storage): StreamedResponse
    {
        $notice = Notice::query()->visibleTo(['staff'])->whereNotNull('attachment_path')->find((int) $request->route('notice'));
        abort_unless($notice !== null && $storage->owns($notice->attachment_path) && $storage->disk()->exists($notice->attachment_path), 404);

        return $storage->disk()->download($notice->attachment_path, $notice->attachment_name ?? 'attachment');
    }
}
