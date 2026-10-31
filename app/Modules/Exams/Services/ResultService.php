<?php

namespace App\Modules\Exams\Services;

use App\Models\User;
use App\Modules\Exams\Grading\GradingConfig;
use App\Modules\Exams\Grading\GradingEngine;
use App\Modules\Exams\Grading\Hundredths;
use App\Modules\Exams\Jobs\NotifyResultPublished;
use App\Modules\Exams\Models\Examination;
use App\Modules\Exams\Models\ExamMark;
use App\Modules\Exams\Models\ExamMarkSheet;
use App\Modules\Exams\Models\ExamResult;
use App\Modules\Exams\Models\ExamSubject;
use App\Modules\Exams\Models\ExamSubjectResult;
use App\Modules\Exams\Models\GradingScale;
use App\Modules\Exams\Models\ResultCalculation;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * calculate → approve → publish, and reopen.
 *
 * Each calculation is a new numbered ResultCalculation holding the grading
 * configuration and exam setup it used plus a hash of every mark, so a
 * published result can be reproduced and checked later. Earlier versions are
 * never deleted; the examination points at the current one.
 */
class ResultService
{
    public function __construct(
        private readonly GradingEngine $engine,
        private readonly MarkEntryService $marks,
        private readonly AuditLogger $audit,
    ) {}

    public function calculate(Examination $exam, User $user): ResultCalculation
    {
        return DB::transaction(function () use ($exam, $user) {
            $exam = Examination::query()->whereKey($exam->id)->lockForUpdate()->firstOrFail();

            if (! in_array($exam->status, ['marks_entry', 'calculated'], true)) {
                throw ValidationException::withMessages(['exam' => __('exams.cannot_calculate')]);
            }

            $pending = ExamMarkSheet::query()
                ->whereIn('exam_subject_id', $exam->subjects()->select('id'))
                ->where('status', '!=', 'verified')
                ->count();

            if ($pending > 0) {
                throw ValidationException::withMessages(['exam' => __('exams.sheets_unverified', ['count' => $pending])]);
            }

            [$config, $setup, $students] = $this->inputs($exam);
            $outcomes = $this->engine->grade($config, $students);

            $version = (int) ResultCalculation::query()->where('examination_id', $exam->id)->max('version') + 1;
            $calculation = ResultCalculation::create([
                'examination_id' => $exam->id,
                'version' => $version,
                'engine_version' => GradingEngine::VERSION,
                'grading_snapshot' => $config->snapshot(),
                'setup_snapshot' => $setup,
                'config_hash' => $this->configHash($config, $setup),
                'input_hash' => hash('sha256', json_encode($students)),
                'student_count' => count($outcomes),
                'calculated_by' => $user->id,
                'calculated_at' => now(),
            ]);

            $this->store($calculation, $exam, $outcomes);

            $exam->forceFill(['status' => 'calculated', 'current_calculation_id' => $calculation->id])->save();
            $this->audit->tenant('exam.results_calculated', $exam, [], ['version' => $version, 'students' => count($outcomes)]);

            return $calculation;
        });
    }

    public function approve(Examination $exam, User $user): void
    {
        if ($exam->status !== 'calculated') {
            throw ValidationException::withMessages(['exam' => __('exams.cannot_approve')]);
        }

        if ($this->isStale($exam)) {
            throw ValidationException::withMessages(['exam' => __('exams.results_stale')]);
        }

        $exam->forceFill(['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now()])->save();
        $this->audit->tenant('exam.results_approved', $exam, [], ['version' => $exam->currentCalculation?->version]);
    }

    public function publish(Examination $exam, User $user, bool $notify): void
    {
        if ($exam->status !== 'approved') {
            throw ValidationException::withMessages(['exam' => __('exams.cannot_publish')]);
        }

        if ($this->isStale($exam)) {
            throw ValidationException::withMessages(['exam' => __('exams.results_stale')]);
        }

        DB::transaction(function () use ($exam, $user) {
            $exam->forceFill(['status' => 'published', 'published_by' => $user->id, 'published_at' => now()])->save();
            GradingScale::query()->whereKey($exam->grading_scale_id)->whereNull('locked_at')->update(['locked_at' => now()]);
            $this->audit->tenant('exam.results_published', $exam, [], ['version' => $exam->currentCalculation?->version]);
        });

        if ($notify) {
            NotifyResultPublished::dispatch($exam->id);
        }
    }

    /**
     * Back to marks entry. Results already calculated stay on record as earlier
     * versions; the next calculation becomes a new version.
     */
    public function reopen(Examination $exam, string $reason): void
    {
        if (! in_array($exam->status, ['calculated', 'approved', 'published'], true)) {
            throw ValidationException::withMessages(['exam' => __('exams.cannot_reopen')]);
        }

        $old = $exam->status;
        $exam->forceFill(['status' => 'marks_entry', 'approved_by' => null, 'approved_at' => null, 'published_by' => null, 'published_at' => null])->save();
        $this->audit->tenant('exam.reopened', $exam, ['status' => $old], ['status' => 'marks_entry', 'reason' => $reason]);
    }

    /** The grading scale or setup changed since the current calculation. */
    public function isStale(Examination $exam): bool
    {
        $calculation = $exam->currentCalculation;

        if ($calculation === null) {
            return false;
        }

        $config = GradingConfig::fromScale($exam->gradingScale()->with('items')->firstOrFail());

        return $calculation->config_hash !== $this->configHash($config, $this->setupSnapshot($exam));
    }

    /**
     * Recalculate a stored version from its own snapshot and the marks as they
     * are now, and report whether the outcome is identical. Used for audits.
     */
    public function reproduces(ResultCalculation $calculation): bool
    {
        $exam = Examination::query()->findOrFail($calculation->examination_id);
        [, , $students] = $this->inputs($exam);

        if (hash('sha256', json_encode($students)) !== $calculation->input_hash) {
            return false; // marks changed since
        }

        $outcomes = $this->engine->grade(GradingConfig::fromSnapshot($calculation->grading_snapshot), $students);
        $stored = ExamResult::query()->where('result_calculation_id', $calculation->id)->get()->keyBy('student_id');

        foreach ($outcomes as $o) {
            $r = $stored->get($o['student_id']);

            if ($r === null || $r->gpa !== ($o['gpa'] === null ? null : Hundredths::format($o['gpa'])) || $r->letter_grade !== $o['letter_grade']
                || $r->obtained_marks !== Hundredths::format($o['obtained']) || $r->class_position !== $o['class_position']) {
                return false;
            }
        }

        return $stored->count() === count($outcomes);
    }

    /**
     * @return array{0: GradingConfig, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>}
     */
    private function inputs(Examination $exam): array
    {
        $config = GradingConfig::fromScale($exam->gradingScale()->with('items')->firstOrFail());
        $subjects = $exam->subjects()->with('components')->orderBy('class_id')->orderBy('sort_order')->orderBy('id')->get();
        $marks = ExamMark::query()
            ->with('parts')
            ->whereIn('exam_subject_id', $subjects->pluck('id'))
            ->get()
            ->groupBy('student_id');

        $students = [];

        foreach ($subjects->groupBy('class_id') as $classId => $classSubjects) {
            foreach ($this->marks->roster($exam, (int) $classId) as $enrollment) {
                $studentMarks = $marks->get($enrollment->student_id, collect())->keyBy('exam_subject_id');
                $rows = [];

                foreach ($classSubjects as $subject) {
                    /** @var ExamSubject $subject */
                    $mark = $studentMarks->get($subject->id);
                    $parts = $mark?->parts->pluck('marks', 'exam_subject_component_id') ?? collect();

                    $rows[] = [
                        'exam_subject_id' => $subject->id,
                        'subject_id' => $subject->subject_id,
                        'type' => $subject->subject_type,
                        'status' => $mark->status ?? 'absent',
                        'full_marks' => $subject->full_marks,
                        'pass_marks' => $subject->pass_marks,
                        'components' => $subject->components->map(fn ($c) => [
                            'id' => $c->id, 'name' => $c->name, 'name_bn' => $c->name_bn, 'full_marks' => $c->full_marks,
                            'pass_marks' => $c->pass_marks, 'marks' => $parts->get($c->id),
                        ])->all(),
                    ];
                }

                $students[] = [
                    'student_id' => $enrollment->student_id,
                    'class_id' => (int) $classId,
                    'section_id' => $enrollment->section_id,
                    'subjects' => $rows,
                ];
            }
        }

        usort($students, fn ($a, $b) => $a['student_id'] <=> $b['student_id']);

        return [$config, $this->setupSnapshot($exam, $subjects), $students];
    }

    /** @return list<array<string, mixed>> */
    private function setupSnapshot(Examination $exam, $subjects = null): array
    {
        $subjects ??= $exam->subjects()->with('components')->orderBy('class_id')->orderBy('sort_order')->orderBy('id')->get();

        return $subjects->map(fn (ExamSubject $s) => [
            'id' => $s->id, 'class_id' => $s->class_id, 'subject_id' => $s->subject_id, 'type' => $s->subject_type,
            'full_marks' => $s->full_marks, 'pass_marks' => $s->pass_marks,
            'components' => $s->components->map(fn ($c) => [$c->id, $c->name, $c->full_marks, $c->pass_marks])->all(),
        ])->values()->all();
    }

    /** @param  list<array<string, mixed>>  $setup */
    private function configHash(GradingConfig $config, array $setup): string
    {
        return hash('sha256', json_encode([GradingEngine::VERSION, $config->snapshot()['bands'], $config->rules, $setup]));
    }

    /** @param  list<array<string, mixed>>  $outcomes */
    private function store(ResultCalculation $calculation, Examination $exam, array $outcomes): void
    {
        $f = fn (?int $v) => $v === null ? null : Hundredths::format($v);

        foreach ($outcomes as $o) {
            $result = ExamResult::create([
                'result_calculation_id' => $calculation->id,
                'examination_id' => $exam->id,
                'student_id' => $o['student_id'],
                'class_id' => $o['class_id'],
                'section_id' => $o['section_id'],
                'full_marks' => $f($o['full']),
                'obtained_marks' => $f($o['obtained']),
                'percentage' => $f($o['percentage']),
                'gpa' => $f($o['gpa']),
                'gpa_without_optional' => $f($o['gpa_without_optional']),
                'letter_grade' => $o['letter_grade'],
                'result_status' => $o['status'],
                'failed_subjects' => $o['failed_subjects'],
                'class_position' => $o['class_position'],
                'section_position' => $o['section_position'],
            ]);

            foreach ($o['subjects'] as $s) {
                ExamSubjectResult::create([
                    'exam_result_id' => $result->id,
                    'exam_subject_id' => $s['exam_subject_id'],
                    'subject_id' => $s['subject_id'],
                    'status' => $s['status'],
                    'counted_as' => $s['counted_as'],
                    'full_marks' => $f($s['full']),
                    'obtained_marks' => $f($s['obtained']),
                    'percentage' => $f($s['percentage']),
                    'grade' => $s['grade'],
                    'grade_point' => $f($s['gp']),
                    'passed' => $s['passed'],
                    'components' => $s['components'],
                ]);
            }
        }
    }
}
