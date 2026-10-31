<?php

namespace App\Modules\Exams\Services;

use App\Modules\Academics\Models\ClassSubject;
use App\Modules\Academics\Models\Section;
use App\Modules\Exams\Grading\Hundredths;
use App\Modules\Exams\Models\Examination;
use App\Modules\Exams\Models\ExamMarkSheet;
use App\Modules\Exams\Models\ExamSubject;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Exam configuration: which subjects each class sits, their components,
 * full and pass marks. Setup is editable only while the exam is a draft;
 * opening marks entry freezes it and creates one mark sheet per section.
 */
class ExamSetupService
{
    /** Component templates offered when adding subjects (shares of the subject's full marks, in percent). */
    public const TEMPLATES = [
        'written' => [['Written', 'লিখিত', 100]],
        'written_mcq' => [['Written', 'লিখিত', 70], ['MCQ', 'বহুনির্বাচনী', 30]],
        'written_mcq_practical' => [['Written', 'লিখিত', 50], ['MCQ', 'বহুনির্বাচনী', 25], ['Practical', 'ব্যবহারিক', 25]],
        'written_ca' => [['Written', 'লিখিত', 80], ['Continuous assessment', 'ধারাবাহিক মূল্যায়ন', 20]],
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data): Examination
    {
        return DB::transaction(function () use ($data) {
            $exam = Examination::create($data);
            $this->audit->tenant('exam.created', $exam, [], ['name' => $exam->name]);

            return $exam;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Examination $exam, array $data): Examination
    {
        if (! $exam->isConfigurable()) {
            // Only cosmetic fields once marks entry has started.
            $data = array_intersect_key($data, array_flip(['name', 'name_bn', 'starts_on', 'ends_on', 'exam_type_id']));
        }

        $exam->update($data);

        return $exam;
    }

    /**
     * Add every subject mapped to the class (or the given ones) using a component template.
     *
     * @param  list<int>|null  $subjectIds
     */
    public function addClassSubjects(Examination $exam, int $classId, ?array $subjectIds, string $template): int
    {
        $this->assertConfigurable($exam);
        $parts = self::TEMPLATES[$template] ?? self::TEMPLATES['written'];

        $mapped = ClassSubject::query()
            ->where('academic_session_id', $exam->academic_session_id)
            ->where('class_id', $classId)
            ->when($subjectIds !== null, fn ($q) => $q->whereIn('subject_id', $subjectIds))
            ->orderBy('sort_order')
            ->orderBy('subject_id')
            ->get();

        if ($mapped->isEmpty()) {
            throw ValidationException::withMessages(['class_id' => __('exams.no_class_subjects')]);
        }

        return DB::transaction(function () use ($exam, $classId, $mapped, $parts) {
            $added = 0;
            $existing = ExamSubject::query()->where('examination_id', $exam->id)->where('class_id', $classId)->pluck('subject_id')->all();

            foreach ($mapped as $i => $cs) {
                if (in_array($cs->subject_id, $existing, true)) {
                    continue;
                }

                $full = Hundredths::parse((string) $cs->full_marks);
                $subject = ExamSubject::create([
                    'examination_id' => $exam->id,
                    'class_id' => $classId,
                    'subject_id' => $cs->subject_id,
                    'subject_type' => $cs->is_optional ? 'optional' : 'compulsory',
                    'pass_marks' => $cs->pass_marks,
                    'sort_order' => $cs->sort_order ?: $i + 1,
                ]);

                $remaining = $full;

                foreach ($parts as $n => [$name, $nameBn, $share]) {
                    $marks = $n === count($parts) - 1 ? $remaining : intdiv($full * $share, 10000) * 100;
                    $remaining -= $marks;
                    $subject->components()->create(['name' => $name, 'name_bn' => $nameBn, 'full_marks' => Hundredths::format($marks), 'sort_order' => $n]);
                }

                $this->syncFullMarks($subject);
                $added++;
            }

            return $added;
        });
    }

    /**
     * @param  array<string, mixed>  $data  subject_type, exam_date, starts_at, duration_minutes, pass_marks,
     *                                      components: list<{id?, name, name_bn, full_marks, pass_marks}>
     */
    public function updateSubject(ExamSubject $subject, array $data): ExamSubject
    {
        $exam = $subject->examination;
        $schedule = array_intersect_key($data, array_flip(['exam_date', 'starts_at', 'duration_minutes']));

        if (! $exam->isConfigurable()) {
            $subject->update($schedule); // the timetable may still move

            return $subject;
        }

        $components = $data['components'];
        $full = array_sum(array_map(fn ($c) => Hundredths::parse((string) $c['full_marks']), $components));

        if ($full <= 0) {
            throw ValidationException::withMessages(['components' => __('exams.components_required')]);
        }

        if (Hundredths::parse((string) $data['pass_marks']) > $full) {
            throw ValidationException::withMessages(['pass_marks' => __('exams.pass_above_full')]);
        }

        foreach ($components as $i => $c) {
            if (($c['pass_marks'] ?? null) !== null && $c['pass_marks'] !== '' && Hundredths::parse((string) $c['pass_marks']) > Hundredths::parse((string) $c['full_marks'])) {
                throw ValidationException::withMessages(["components.{$i}.pass_marks" => __('exams.pass_above_full')]);
            }
        }

        return DB::transaction(function () use ($subject, $data, $schedule, $components) {
            $subject->update($schedule + ['subject_type' => $data['subject_type'], 'pass_marks' => $data['pass_marks']]);
            $keep = [];

            foreach (array_values($components) as $i => $c) {
                $attrs = ['name' => $c['name'], 'name_bn' => $c['name_bn'] ?? null, 'full_marks' => $c['full_marks'],
                    'pass_marks' => ($c['pass_marks'] ?? '') === '' ? null : $c['pass_marks'], 'sort_order' => $i];
                $component = ! empty($c['id']) ? $subject->components()->whereKey($c['id'])->first() : null;
                $component ? $component->update($attrs) : $component = $subject->components()->create($attrs);
                $keep[] = $component->id;
            }

            $subject->components()->whereNotIn('id', $keep)->delete();
            $this->syncFullMarks($subject);

            return $subject->refresh();
        });
    }

    public function removeSubject(ExamSubject $subject): void
    {
        $this->assertConfigurable($subject->examination);

        DB::transaction(function () use ($subject) {
            $subject->components()->delete();
            $subject->delete();
        });
    }

    public function openMarksEntry(Examination $exam): void
    {
        $this->assertConfigurable($exam);
        $subjects = $exam->subjects()->get();

        if ($subjects->isEmpty()) {
            throw ValidationException::withMessages(['exam' => __('exams.no_subjects')]);
        }

        if ($subjects->contains(fn (ExamSubject $s) => Hundredths::parse($s->full_marks) <= 0)) {
            throw ValidationException::withMessages(['exam' => __('exams.components_required')]);
        }

        DB::transaction(function () use ($exam, $subjects) {
            $sections = Section::query()->whereIn('class_id', $subjects->pluck('class_id')->unique())->where('is_active', true)->get()->groupBy('class_id');

            foreach ($subjects as $subject) {
                foreach ($sections->get($subject->class_id, collect()) as $section) {
                    ExamMarkSheet::query()->firstOrCreate(
                        ['exam_subject_id' => $subject->id, 'section_id' => $section->id],
                        ['class_id' => $subject->class_id],
                    );
                }
            }

            $exam->forceFill(['status' => 'marks_entry'])->save();
            $this->audit->tenant('exam.marks_entry_opened', $exam);
        });
    }

    private function syncFullMarks(ExamSubject $subject): void
    {
        $full = $subject->components()->get()->sum(fn ($c) => Hundredths::parse($c->full_marks));
        $subject->forceFill(['full_marks' => Hundredths::format($full)])->save();
    }

    private function assertConfigurable(Examination $exam): void
    {
        if (! $exam->isConfigurable()) {
            throw ValidationException::withMessages(['exam' => __('exams.setup_frozen')]);
        }
    }
}
