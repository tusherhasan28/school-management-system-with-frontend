<?php

namespace App\Modules\Exams\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academics\Models\SchoolClass;
use App\Modules\Academics\Services\AcademicOptions;
use App\Modules\Exams\Grading\Hundredths;
use App\Modules\Exams\Models\Examination;
use App\Modules\Exams\Models\ExamMark;
use App\Modules\Exams\Models\ExamMarkSheet;
use App\Modules\Exams\Models\ExamSubject;
use App\Modules\Exams\Models\ExamType;
use App\Modules\Exams\Models\GradingScale;
use App\Modules\Exams\Services\ExamAccess;
use App\Modules\Exams\Services\ExamSetupService;
use App\Modules\Exams\Services\MarkEntryService;
use App\Modules\Exams\Services\ResultService;
use App\Support\Tenancy\TenantRule;
use App\Support\Localization\Localized;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ExamController extends Controller
{
    public function __construct(
        private readonly ExamSetupService $setup,
        private readonly MarkEntryService $marks,
        private readonly ResultService $results,
        private readonly ExamAccess $access,
    ) {}

    public function index(Request $request, AcademicOptions $options): Response
    {
        $user = $request->user();
        abort_unless($this->access->canSeeExams($user), 403);

        $sessionId = (int) ($request->integer('session') ?: $options->currentSessionId());

        return Inertia::render('app/exams/index', [
            'exams' => Examination::query()
                ->with(['examType:id,school_id,name,name_bn', 'gradingScale:id,school_id,name'])
                ->withCount('subjects')
                ->where('academic_session_id', $sessionId)
                ->orderByDesc('starts_on')->orderByDesc('id')
                ->get()
                ->map(fn (Examination $e) => [
                    'id' => $e->id, 'name' => $e->name, 'name_bn' => $e->name_bn, 'status' => $e->status,
                    'type' => Localized::name($e->examType?->name, $e->examType?->name_bn), 'scale' => $e->gradingScale?->name,
                    'starts_on' => $e->starts_on?->toDateString(), 'ends_on' => $e->ends_on?->toDateString(),
                    'subjects' => $e->subjects_count, 'published_at' => $e->published_at?->toIso8601String(),
                ]),
            'sessionId' => $sessionId,
            'sessions' => $options->sessions(),
            'types' => ExamType::query()->orderBy('sort_order')->get(['id', 'name', 'name_bn']),
            'scales' => GradingScale::query()->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'is_default']),
            'can' => ['manage' => $user->can('exams.manage')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('exams.manage'), 403);
        $data = $request->validate($this->rules(true));
        $exam = $this->setup->create($data);

        return to_route('exams.show', $exam);
    }

    public function show(Request $request, Examination $exam, AcademicOptions $options): Response
    {
        $user = $request->user();
        abort_unless($this->access->canSeeExams($user), 403);

        $exam->load(['examType:id,school_id,name,name_bn', 'gradingScale:id,school_id,name', 'academicSession:id,school_id,name', 'currentCalculation.calculator:id,school_id,name']);
        $subjects = $exam->subjects()->with(['subject:id,school_id,name,name_bn', 'components', 'sheets.section:id,school_id,name'])->orderBy('sort_order')->orderBy('id')->get();
        $progress = $this->sheetProgress($exam, $subjects);
        $classes = SchoolClass::query()->whereIn('id', $subjects->pluck('class_id'))->orderBy('level_order')->get(['id', 'name', 'school_id']);

        return Inertia::render('app/exams/show', [
            'exam' => [
                'id' => $exam->id, 'name' => $exam->name, 'name_bn' => $exam->name_bn, 'status' => $exam->status,
                'exam_type_id' => $exam->exam_type_id, 'type' => Localized::name($exam->examType?->name, $exam->examType?->name_bn), 'grading_scale_id' => $exam->grading_scale_id,
                'scale' => $exam->gradingScale?->name, 'session' => $exam->academicSession?->name,
                'starts_on' => $exam->starts_on?->toDateString(), 'ends_on' => $exam->ends_on?->toDateString(),
                'approved_at' => $exam->approved_at?->toIso8601String(), 'published_at' => $exam->published_at?->toIso8601String(),
                'calculation' => $exam->currentCalculation ? [
                    'version' => $exam->currentCalculation->version, 'at' => $exam->currentCalculation->calculated_at->toIso8601String(),
                    'by' => $exam->currentCalculation->calculator?->name, 'students' => $exam->currentCalculation->student_count,
                ] : null,
                'stale' => $this->results->isStale($exam),
                'versions' => $exam->calculations()->count(),
            ],
            'classes' => $classes->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name,
                'subjects' => $subjects->where('class_id', $c->id)->values()->map(fn (ExamSubject $s) => [
                    'id' => $s->id, 'subject' => $s->subject?->name, 'subject_bn' => $s->subject?->name_bn, 'subject_type' => $s->subject_type,
                    'full_marks' => $s->full_marks, 'pass_marks' => $s->pass_marks,
                    'exam_date' => $s->exam_date?->toDateString(), 'starts_at' => $s->starts_at ? substr($s->starts_at, 0, 5) : null, 'duration_minutes' => $s->duration_minutes,
                    'components' => $s->components->map->only(['id', 'name', 'name_bn', 'full_marks', 'pass_marks'])->values(),
                    'sheets' => $s->sheets->sortBy(fn ($sh) => $sh->section?->name)->values()->map(fn (ExamMarkSheet $sh) => [
                        'id' => $sh->id, 'section' => $sh->section?->name, 'status' => $sh->status,
                    ] + ($progress[$sh->id] ?? ['done' => 0, 'total' => 0])),
                ]),
            ]),
            'options' => [
                'classes' => $options->classes(),
                'templates' => array_keys(ExamSetupService::TEMPLATES),
                'types' => ExamType::query()->orderBy('sort_order')->get(['id', 'name']),
                'scales' => GradingScale::query()->orderBy('name')->get(['id', 'name']),
            ],
            'can' => [
                'manage' => $user->can('exams.manage'),
                'approve' => $user->can('results.approve'),
                'publish' => $user->can('results.publish'),
                'verify' => $user->can('marks.verify'),
                'results' => $user->can('results.view'),
            ],
        ]);
    }

    public function update(Request $request, Examination $exam): RedirectResponse
    {
        abort_unless($request->user()->can('exams.manage'), 403);
        $this->setup->update($exam, $request->validate($this->rules(false)));

        return back();
    }

    public function addSubjects(Request $request, Examination $exam): RedirectResponse
    {
        abort_unless($request->user()->can('exams.manage'), 403);
        $data = $request->validate([
            'class_id' => ['required', 'integer', TenantRule::exists('classes')],
            'subject_ids' => ['nullable', 'array'],
            'subject_ids.*' => ['integer'],
            'template' => ['required', Rule::in(array_keys(ExamSetupService::TEMPLATES))],
        ]);

        $added = $this->setup->addClassSubjects($exam, (int) $data['class_id'], $data['subject_ids'] ?? null, $data['template']);
        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('exams.subjects_added', $added, ['count' => $added])]);

        return back();
    }

    public function updateSubject(Request $request, ExamSubject $subject): RedirectResponse
    {
        abort_unless($request->user()->can('exams.manage'), 403);
        $data = $request->validate([
            'subject_type' => ['required', Rule::in(ExamSubject::TYPES)],
            'exam_date' => ['nullable', 'date'],
            'starts_at' => ['nullable', 'date_format:H:i'],
            'duration_minutes' => ['nullable', 'integer', 'between:5,600'],
            'pass_marks' => ['required', 'numeric', 'min:0', 'max:9999', 'decimal:0,2'],
            'components' => ['required', 'array', 'min:1', 'max:8'],
            'components.*.id' => ['nullable', 'integer'],
            'components.*.name' => ['required', 'string', 'max:60'],
            'components.*.name_bn' => ['nullable', 'string', 'max:60'],
            'components.*.full_marks' => ['required', 'numeric', 'min:0', 'max:9999', 'decimal:0,2'],
            'components.*.pass_marks' => ['nullable', 'numeric', 'min:0', 'max:9999', 'decimal:0,2'],
        ]);

        $data['pass_marks'] = (string) $data['pass_marks'];
        $data['components'] = array_map(fn ($c) => ['full_marks' => (string) $c['full_marks'], 'pass_marks' => isset($c['pass_marks']) ? (string) $c['pass_marks'] : null] + $c, $data['components']);
        $this->setup->updateSubject($subject, $data);

        return back();
    }

    public function destroySubject(Request $request, ExamSubject $subject): RedirectResponse
    {
        abort_unless($request->user()->can('exams.manage'), 403);
        $this->setup->removeSubject($subject);

        return back();
    }

    public function open(Request $request, Examination $exam): RedirectResponse
    {
        abort_unless($request->user()->can('exams.manage'), 403);
        $this->setup->openMarksEntry($exam);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('exams.marks_entry_opened')]);

        return back();
    }

    public function calculate(Request $request, Examination $exam): RedirectResponse
    {
        abort_unless($request->user()->can('exams.manage'), 403);
        $calculation = $this->results->calculate($exam, $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('exams.calculated', ['version' => $calculation->version])]);

        return back();
    }

    public function approve(Request $request, Examination $exam): RedirectResponse
    {
        abort_unless($request->user()->can('results.approve'), 403);
        $this->results->approve($exam->load('currentCalculation'), $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('exams.approved')]);

        return back();
    }

    public function publish(Request $request, Examination $exam): RedirectResponse
    {
        abort_unless($request->user()->can('results.publish'), 403);
        $data = $request->validate(['notify' => ['boolean']]);
        $this->results->publish($exam->load('currentCalculation'), $request->user(), (bool) ($data['notify'] ?? false));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('exams.published')]);

        return back();
    }

    public function reopen(Request $request, Examination $exam): RedirectResponse
    {
        $user = $request->user();
        abort_unless($exam->isPublished() ? $user->can('results.publish') : $user->can('exams.manage'), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']]);
        $this->results->reopen($exam, $data['reason']);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('exams.reopened')]);

        return back();
    }

    /**
     * Done/total per sheet with two grouped queries instead of one roster per sheet.
     *
     * @return array<int, array{done: int, total: int}>
     */
    private function sheetProgress(Examination $exam, $subjects): array
    {
        $sheetIds = $subjects->flatMap->sheets->pluck('id');

        if ($sheetIds->isEmpty()) {
            return [];
        }

        $rosterBySection = $subjects->pluck('class_id')->unique()
            ->flatMap(fn ($classId) => $this->marks->roster($exam, $classId))
            ->countBy('section_id');

        $done = ExamMark::query()
            ->whereIn('exam_mark_sheet_id', $sheetIds)
            ->where(fn ($q) => $q->where('status', '!=', 'present')->orWhereNotNull('total'))
            ->groupBy('exam_mark_sheet_id')
            ->selectRaw('exam_mark_sheet_id, COUNT(*) as c')
            ->pluck('c', 'exam_mark_sheet_id');

        $out = [];

        foreach ($subjects->flatMap->sheets as $sheet) {
            $out[$sheet->id] = ['done' => (int) ($done[$sheet->id] ?? 0), 'total' => (int) ($rosterBySection[$sheet->section_id] ?? 0)];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function rules(bool $creating): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'name_bn' => ['nullable', 'string', 'max:150'],
            'exam_type_id' => ['nullable', 'integer', TenantRule::exists('exam_types')],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ] + ($creating ? [
            'academic_session_id' => ['required', 'integer', TenantRule::exists('academic_sessions')],
            'grading_scale_id' => ['required', 'integer', TenantRule::exists('grading_scales')],
        ] : [
            'grading_scale_id' => ['sometimes', 'integer', TenantRule::exists('grading_scales')],
        ]);
    }
}
