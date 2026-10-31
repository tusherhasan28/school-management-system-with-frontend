<?php

namespace App\Modules\Exams\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academics\Models\SchoolClass;
use App\Modules\Exams\Models\Examination;
use App\Modules\Exams\Models\ExamResult;
use App\Modules\Exams\Models\ExamSubject;
use App\Modules\Exams\Services\ExamAccess;
use App\Modules\Exams\Services\ResultReader;
use App\Modules\Teaching\Services\TeachingScope;
use App\Support\Localization\Localized;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Results (tabulation) and printable report cards, always from the exam's
 * current calculation. Unpublished results are shown to staff marked as draft.
 */
class ResultController extends Controller
{
    public function __construct(
        private readonly ExamAccess $access,
        private readonly TeachingScope $scope,
        private readonly ResultReader $reader,
    ) {}

    public function index(Request $request, Examination $exam): Response
    {
        $user = $request->user();
        [$classId, $sectionId, $classes] = $this->selection($request, $exam);

        abort_unless($classId !== null && $this->access->canViewResults($user, $exam, $classId, $sectionId), 403);

        $subjects = $this->reader->subjects($exam, $classId);
        $results = $this->reader->results($exam, $classId, $sectionId);
        $rolls = $this->reader->rolls($exam, $results->pluck('student_id'));

        return Inertia::render('app/exams/results', [
            'exam' => $this->reader->examSummary($exam),
            'classes' => $classes,
            'filters' => ['class' => $classId, 'section' => $sectionId],
            'subjects' => $subjects->map(fn (ExamSubject $s) => ['id' => $s->id, 'name' => Localized::name($s->subject?->name, $s->subject?->name_bn), 'type' => $s->subject_type])->values(),
            'rows' => $results->map(fn (ExamResult $r) => [
                'student_id' => $r->student_id, 'name' => $r->student?->full_name, 'admission_no' => $r->student?->admission_no,
                'roll' => $rolls[$r->student_id] ?? null, 'section' => $r->section?->name,
                'full_marks' => $r->full_marks, 'obtained_marks' => $r->obtained_marks, 'percentage' => $r->percentage,
                'gpa' => $r->gpa, 'letter_grade' => $r->letter_grade, 'status' => $r->result_status, 'failed_subjects' => $r->failed_subjects,
                'position' => $sectionId ? $r->section_position : $r->class_position,
                'subjects' => $r->subjectResults->mapWithKeys(fn ($s) => [$s->exam_subject_id => [
                    'obtained' => $s->obtained_marks, 'grade' => $s->grade, 'passed' => $s->passed, 'status' => $s->status, 'counted_as' => $s->counted_as,
                ]]),
            ])->values(),
            'stats' => [
                'total' => $results->count(),
                'pass' => $results->where('result_status', 'pass')->count(),
                'fail' => $results->where('result_status', 'fail')->count(),
                'grades' => $this->reader->gradeCounts($exam, $results),
            ],
        ]);
    }

    public function reportCards(Request $request, Examination $exam): Response
    {
        [$classId, $sectionId] = $this->selection($request, $exam);
        abort_unless($classId !== null && $this->access->canViewResults($request->user(), $exam, $classId, $sectionId), 403);

        return Inertia::render('app/exams/report-cards', $this->reader->props($exam, $classId, $sectionId, $request->integer('student') ?: null));
    }

    /**
     * @return array{0: int|null, 1: int|null, 2: list<array<string, mixed>>}
     */
    private function selection(Request $request, Examination $exam): array
    {
        $user = $request->user();
        $classIds = $exam->subjects()->distinct()->pluck('class_id');
        $classes = SchoolClass::query()->with(['sections' => fn ($q) => $q->orderBy('name')])->whereIn('id', $classIds)->orderBy('level_order')->get();

        // Class teachers without results.view only see their own sections.
        if (! $user->can('results.view')) {
            $own = collect($this->scope->sections($user, $exam->academic_session_id))->where('class_teacher', true);
            $classes = $classes->filter(fn ($c) => $own->contains('class_id', $c->id))->each(
                fn ($c) => $c->setRelation('sections', $c->sections->filter(fn ($s) => $own->contains('section_id', $s->id))->values())
            )->values();
        }

        $classId = $request->integer('class') ?: $classes->first()?->id;
        $sectionId = $request->integer('section') ?: null;

        if (! $user->can('results.view') && $sectionId === null) {
            $sectionId = $classes->firstWhere('id', $classId)?->sections->first()?->id;
        }

        return [$classId, $sectionId, $classes->map(fn ($c) => [
            'id' => $c->id, 'name' => $c->name, 'sections' => $c->sections->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values(),
        ])->values()->all()];
    }

}
