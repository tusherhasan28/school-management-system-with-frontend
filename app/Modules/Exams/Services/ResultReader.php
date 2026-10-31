<?php

namespace App\Modules\Exams\Services;

use App\Modules\Academics\Models\SchoolClass;
use App\Modules\Academics\Models\Section;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Exams\Grading\GradingConfig;
use App\Modules\Exams\Models\Examination;
use App\Modules\Exams\Models\ExamResult;
use App\Modules\Exams\Models\ExamSubject;
use App\Modules\People\Models\StudentEnrollment;
use App\Modules\Teaching\Models\TeacherAssignment;
use App\Support\Localization\Localized;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Reads results of an exam's current calculation for the results table,
 * staff report cards and the family portals. Callers decide who may see what.
 */
class ResultReader
{
    public function __construct(private readonly AttendanceService $attendance, private readonly TenantContext $tenant) {}

    /**
     * Props for the report-cards page: one card per result of the class
     * (optionally one section or one student), from the current calculation.
     *
     * @return array<string, mixed>
     */
    public function props(Examination $exam, int $classId, ?int $sectionId, ?int $studentId): array
    {
        $results = $this->results($exam, $classId, $sectionId)->when($studentId, fn ($c) => $c->where('student_id', $studentId));
        abort_if($studentId && $results->isEmpty(), 404);

        $rolls = $this->rolls($exam, $results->pluck('student_id'));
        $results = $results->sortBy(fn ($r) => sprintf('%s-%010s', $r->section?->name, $rolls[$r->student_id] ?? ''))->values();
        $subjects = $this->subjects($exam, $classId)->keyBy('id');
        $school = $this->tenant->require();
        $attendance = $this->attendance;
        $class = SchoolClass::query()->findOrFail($classId);
        $calculation = $exam->currentCalculation()->first();
        $config = $calculation ? GradingConfig::fromSnapshot($calculation->grading_snapshot) : null;

        // Attendance from the start of the session to the end of the exam.
        $from = CarbonImmutable::parse($exam->academicSession->start_date);
        $to = CarbonImmutable::parse(($exam->ends_on ?? $exam->starts_on ?? now())->toDateString());
        $attendanceBySection = $results->pluck('section_id')->filter()->unique()->mapWithKeys(fn ($id) => [
            $id => collect($attendance->summary(Section::query()->findOrFail($id), $from, $to))->keyBy('student_id'),
        ]);

        $classTeachers = TeacherAssignment::query()
            ->with('teacher:id,school_id,full_name')
            ->where('academic_session_id', $exam->academic_session_id)
            ->where('class_id', $classId)
            ->where('assignment_type', 'class_teacher')
            ->get()
            ->mapWithKeys(fn ($a) => [$a->section_id => $a->teacher?->full_name]);

        return [
            'school' => ['name' => $school->name, 'name_bn' => $school->name_bn, 'address' => $school->address, 'phone' => $school->phone, 'email' => $school->email],
            'exam' => $this->examSummary($exam) + ['session' => $exam->academicSession?->name],
            'class' => ['name' => $class->name, 'name_bn' => $class->name_bn],
            'bands' => $config ? array_map(fn ($b) => ['grade' => $b['grade'], 'range' => $b['min_percentage'].'–'.$b['max_percentage'], 'gp' => $b['grade_point'], 'remarks' => $b['remarks'], 'remarks_bn' => $b['remarks_bn']], $config->snapshot()['bands']) : [],
            'showGpa' => ($config?->rules['gpa_method'] ?? 'none') !== 'none',
            'cards' => $results->map(function (ExamResult $r) use ($subjects, $rolls, $attendanceBySection, $classTeachers) {
                $guardian = $r->student?->guardians->first();

                return [
                    'student' => [
                        'id' => $r->student_id, 'name' => $r->student?->full_name, 'name_bn' => $r->student?->name_bn,
                        'admission_no' => $r->student?->admission_no, 'roll' => $rolls[$r->student_id] ?? null, 'section' => $r->section?->name,
                        'guardian' => $guardian?->full_name,
                    ],
                    'result' => $r->only(['full_marks', 'obtained_marks', 'percentage', 'gpa', 'gpa_without_optional', 'letter_grade', 'failed_subjects', 'class_position', 'section_position']) + ['status' => $r->result_status],
                    'subjects' => $r->subjectResults->sortBy(fn ($s) => $subjects->get($s->exam_subject_id)?->sort_order)->values()->map(fn ($s) => [
                        'name' => $s->subject?->name, 'name_bn' => $s->subject?->name_bn, 'counted_as' => $s->counted_as, 'status' => $s->status,
                        'full' => $s->full_marks, 'obtained' => $s->obtained_marks, 'grade' => $s->grade, 'gp' => $s->grade_point, 'passed' => $s->passed,
                        'components' => $s->components,
                    ]),
                    'attendance' => $r->section_id ? ($attendanceBySection->get($r->section_id)?->get($r->student_id)) : null,
                    'class_teacher' => $classTeachers[$r->section_id] ?? null,
                ];
            })->values(),
        ];
    }

    /** @return Collection<int, ExamResult> */
    public function results(Examination $exam, int $classId, ?int $sectionId): Collection
    {
        if ($exam->current_calculation_id === null) {
            return collect();
        }

        return ExamResult::query()
            ->with([
                'student' => fn ($q) => $q->select(['id', 'school_id', 'full_name', 'name_bn', 'admission_no'])->with(['guardians' => fn ($q) => $q->wherePivot('is_primary', true)]),
                'section:id,school_id,name',
                'subjectResults.subject:id,school_id,name,name_bn',
            ])
            ->where('result_calculation_id', $exam->current_calculation_id)
            ->where('class_id', $classId)
            ->when($sectionId, fn ($q) => $q->where('section_id', $sectionId))
            ->get()
            ->sortBy(fn (ExamResult $r) => [($sectionId ? $r->section_position : $r->class_position) ?? PHP_INT_MAX, $r->student?->full_name])
            ->values();
    }

    /** @return Collection<int, ExamSubject> */
    public function subjects(Examination $exam, int $classId): Collection
    {
        return $exam->subjects()->with('subject:id,school_id,name,name_bn')->where('class_id', $classId)->orderBy('sort_order')->orderBy('id')->get();
    }

    /**
     * @param  Collection<int, int>  $studentIds
     * @return array<int, string|null>
     */
    public function rolls(Examination $exam, Collection $studentIds): array
    {
        return StudentEnrollment::query()
            ->where('academic_session_id', $exam->academic_session_id)
            ->whereIn('student_id', $studentIds)
            ->pluck('roll_no', 'student_id')
            ->all();
    }

    /** @return array<string, mixed> */
    public function examSummary(Examination $exam): array
    {
        return [
            'id' => $exam->id, 'name' => $exam->name, 'name_bn' => $exam->name_bn, 'title' => Localized::name($exam->name, $exam->name_bn), 'status' => $exam->status,
            'published' => $exam->isPublished(), 'published_at' => $exam->published_at?->toIso8601String(),
            'starts_on' => $exam->starts_on?->toDateString(), 'ends_on' => $exam->ends_on?->toDateString(),
            'version' => $exam->currentCalculation()->value('version'),
        ];
    }

    /**
     * Students per grade, in the scale's band order (A+, A, A-, …, F).
     *
     * @param  Collection<int, ExamResult>  $results
     * @return array<string, int>
     */
    public function gradeCounts(Examination $exam, Collection $results): array
    {
        $counts = $results->countBy('letter_grade');
        $bands = $exam->currentCalculation()->first()?->grading_snapshot['bands'] ?? [];
        $ordered = [];

        foreach ($bands as $band) {
            if ($counts->has($band['grade'])) {
                $ordered[$band['grade']] = $counts[$band['grade']];
            }
        }

        return $ordered + $counts->all();
    }
}
