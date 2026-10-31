<?php

namespace App\Modules\Exams\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Exams\Models\Examination;
use App\Modules\Exams\Models\ExamMark;
use App\Modules\Exams\Models\ExamMarkSheet;
use App\Modules\Exams\Services\ExamAccess;
use App\Modules\Exams\Services\MarkEntryService;
use App\Modules\Teaching\Services\TeachingScope;
use App\Support\Localization\Localized;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MarkSheetController extends Controller
{
    public function __construct(
        private readonly MarkEntryService $marks,
        private readonly ExamAccess $access,
        private readonly TeachingScope $scope,
    ) {}

    /** Mark sheets the user can work on in exams that are open for marks. */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $all = $user->can('marks.enter_all') || $user->can('marks.verify');
        abort_unless($all || $user->can('marks.enter'), 403);

        $sheets = ExamMarkSheet::query()
            ->with(['examSubject.examination:id,school_id,name,status,academic_session_id', 'examSubject.subject:id,school_id,name,name_bn', 'examSubject.schoolClass:id,school_id,name,level_order', 'section:id,school_id,name'])
            ->whereHas('examSubject.examination', fn ($q) => $q->where('status', 'marks_entry'))
            ->get()
            ->filter(fn (ExamMarkSheet $s) => $all || $this->access->canEnter($user, $s))
            ->sortBy(fn (ExamMarkSheet $s) => sprintf('%010d-%05d-%s-%s', $s->examSubject->examination_id, $s->examSubject->schoolClass?->level_order, $s->section?->name, $s->examSubject->subject?->name))
            ->values();

        return Inertia::render('app/exams/marks-index', [
            'sheets' => $sheets->map(fn (ExamMarkSheet $s) => [
                'id' => $s->id, 'status' => $s->status, 'exam' => Localized::name($s->examSubject->examination->name, $s->examSubject->examination->name_bn),
                'class' => $s->examSubject->schoolClass?->name, 'section' => $s->section?->name, 'subject' => Localized::name($s->examSubject->subject?->name, $s->examSubject->subject?->name_bn),
                'exam_date' => $s->examSubject->exam_date?->toDateString(), 'can_enter' => $this->access->canEnter($user, $s),
            ]),
            'canVerify' => $user->can('marks.verify'),
            'results' => $this->classTeacherResults($user),
        ]);
    }

    /**
     * Calculated results of the class teacher's own sections (current session),
     * for teachers who cannot open the exams area.
     *
     * @return list<array<string, mixed>>
     */
    private function classTeacherResults(\App\Models\User $user): array
    {
        if ($user->can('results.view')) {
            return [];
        }

        $own = collect($this->scope->sections($user))->where('class_teacher', true);

        if ($own->isEmpty()) {
            return [];
        }

        $sectionNames = \App\Modules\Academics\Models\Section::query()->with('schoolClass:id,school_id,name')->whereIn('id', $own->pluck('section_id'))->get()->keyBy('id');

        return Examination::query()
            ->whereNotNull('current_calculation_id')
            ->whereHas('subjects', fn ($q) => $q->whereIn('class_id', $own->pluck('class_id')))
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->flatMap(fn (Examination $e) => $own->map(fn ($s) => [
                'exam_id' => $e->id, 'exam' => Localized::name($e->name, $e->name_bn), 'class_id' => $s['class_id'], 'section_id' => $s['section_id'],
                'label' => trim(($sectionNames[$s['section_id']]?->schoolClass?->name ?? '').' · '.($sectionNames[$s['section_id']]?->name ?? '')),
                'published' => $e->isPublished(),
            ]))
            ->values()
            ->all();
    }

    public function show(Request $request, ExamMarkSheet $sheet): Response
    {
        $user = $request->user();
        $sheet->load(['examSubject.examination', 'examSubject.components', 'examSubject.subject:id,school_id,name,name_bn', 'examSubject.schoolClass:id,school_id,name', 'section:id,school_id,name', 'submitter:id,school_id,name', 'verifier:id,school_id,name']);
        abort_unless($this->access->canViewSheet($user, $sheet), 403);

        $exam = $sheet->examSubject->examination;
        $existing = ExamMark::query()->with('parts')->where('exam_mark_sheet_id', $sheet->id)->get()->keyBy('student_id');
        $canEnter = $this->access->canEnter($user, $sheet);

        return Inertia::render('app/exams/marks-sheet', [
            'sheet' => [
                'id' => $sheet->id, 'status' => $sheet->status,
                'exam' => ['id' => $exam->id, 'name' => Localized::name($exam->name, $exam->name_bn), 'status' => $exam->status],
                'class' => $sheet->examSubject->schoolClass?->name, 'section' => $sheet->section?->name,
                'subject' => Localized::name($sheet->examSubject->subject?->name, $sheet->examSubject->subject?->name_bn), 'subject_type' => $sheet->examSubject->subject_type,
                'full_marks' => $sheet->examSubject->full_marks, 'pass_marks' => $sheet->examSubject->pass_marks,
                'submitted' => $sheet->submitted_at ? ['by' => $sheet->submitter?->name, 'at' => $sheet->submitted_at->toIso8601String()] : null,
                'verified' => $sheet->verified_at ? ['by' => $sheet->verifier?->name, 'at' => $sheet->verified_at->toIso8601String()] : null,
            ],
            'components' => $sheet->examSubject->components->map->only(['id', 'name', 'name_bn', 'full_marks', 'pass_marks'])->values(),
            'students' => $this->marks->roster($exam, $sheet->class_id, $sheet->section_id)->map(function ($e) use ($existing) {
                $mark = $existing->get($e->student_id);

                return [
                    'id' => $e->student_id, 'roll' => $e->roll_no, 'name' => $e->student?->full_name, 'name_bn' => $e->student?->name_bn,
                    'admission_no' => $e->student?->admission_no,
                    'status' => $mark->status ?? 'present', 'total' => $mark?->total, 'remarks' => $mark?->remarks,
                    'parts' => $mark?->parts->pluck('marks', 'exam_subject_component_id') ?? (object) [],
                    'saved' => $mark !== null,
                ];
            })->values(),
            'can' => [
                'edit' => $canEnter && $exam->acceptsMarks() && $sheet->status === 'draft',
                'submit' => $canEnter && $exam->acceptsMarks() && $sheet->status === 'draft',
                'verify' => $user->can('marks.verify') && $exam->acceptsMarks() && $sheet->status !== 'verified',
                'return' => $user->can('marks.verify') && $exam->acceptsMarks() && $sheet->status !== 'draft',
            ],
        ]);
    }

    public function save(Request $request, ExamMarkSheet $sheet): RedirectResponse
    {
        $sheet->load('examSubject.examination');
        abort_unless($this->access->canEnter($request->user(), $sheet), 403);

        $data = $request->validate([
            'rows' => ['required', 'array', 'max:500'],
            'rows.*.status' => ['required', 'in:'.implode(',', ExamMark::STATUSES)],
            'rows.*.parts' => ['nullable', 'array'],
            'rows.*.parts.*' => ['nullable'],
            'rows.*.remarks' => ['nullable', 'string', 'max:255'],
        ]);

        $this->marks->save($sheet, $data['rows'], $request->user());

        if ($request->boolean('submit')) {
            $this->marks->submit($sheet->refresh(), $request->user());
            Inertia::flash('toast', ['type' => 'success', 'message' => __('exams.sheet_submitted')]);
        } else {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('exams.marks_saved')]);
        }

        return back();
    }

    public function verify(Request $request, ExamMarkSheet $sheet): RedirectResponse
    {
        abort_unless($request->user()->can('marks.verify'), 403);
        $sheet->load('examSubject.examination');
        $this->marks->verify($sheet, $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('exams.sheet_verified')]);

        return back();
    }

    public function returnSheet(Request $request, ExamMarkSheet $sheet): RedirectResponse
    {
        abort_unless($request->user()->can('marks.verify'), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']]);
        $sheet->load('examSubject.examination');
        $this->marks->returnToDraft($sheet, $data['reason']);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('exams.sheet_returned')]);

        return back();
    }

    /** Verify every complete submitted sheet of an exam in one go. */
    public function verifyAll(Request $request, Examination $exam): RedirectResponse
    {
        abort_unless($request->user()->can('marks.verify'), 403);
        $count = 0;

        ExamMarkSheet::query()
            ->with('examSubject.examination')
            ->whereIn('exam_subject_id', $exam->subjects()->select('id'))
            ->where('status', 'submitted')
            ->get()
            ->each(function (ExamMarkSheet $sheet) use ($request, &$count) {
                if ($this->marks->missing($sheet) === 0) {
                    $this->marks->verify($sheet, $request->user());
                    $count++;
                }
            });

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('exams.sheets_verified', $count, ['count' => $count])]);

        return back();
    }
}
