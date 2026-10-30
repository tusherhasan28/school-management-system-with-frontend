<?php

namespace App\Modules\Homework\Services;

use App\Modules\Homework\Models\Homework;
use App\Modules\Homework\Models\HomeworkSubmission;
use App\Modules\People\Models\StudentEnrollment;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\TenantStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Homework set by a teacher for a class (or one section) and subject. A
 * submission row is created for every enrolled student so the teacher can
 * track who submitted, mark and give feedback. (Students submit online once
 * the student portal arrives in Milestone 6.)
 */
class HomeworkService
{
    public function __construct(
        private readonly TenantStorage $storage,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?UploadedFile $attachment = null): Homework
    {
        return DB::transaction(function () use ($data, $attachment) {
            $homework = new Homework($data);
            $homework->created_by = Auth::guard('web')->id();
            $this->storeAttachment($homework, $attachment);
            $homework->save();

            $this->syncRoster($homework);
            $this->audit->tenant('homework.created', $homework, new: $homework->only(['title', 'class_id', 'section_id', 'subject_id', 'due_at']));

            return $homework;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Homework $homework, array $data, ?UploadedFile $attachment = null): Homework
    {
        return DB::transaction(function () use ($homework, $data, $attachment) {
            $homework->fill($data);
            $this->storeAttachment($homework, $attachment);
            $homework->save();
            $this->audit->tenant('homework.updated', $homework, new: $data);

            return $homework;
        });
    }

    /**
     * Add pending submission rows for students enrolled in the class/section.
     */
    public function syncRoster(Homework $homework): void
    {
        $studentIds = StudentEnrollment::query()
            ->where('academic_session_id', $homework->academic_session_id)
            ->where('class_id', $homework->class_id)
            ->when($homework->section_id, fn ($q) => $q->where('section_id', $homework->section_id))
            ->where('status', 'enrolled')
            ->pluck('student_id');

        $existing = HomeworkSubmission::query()->where('homework_id', $homework->id)->pluck('student_id')->flip();

        foreach ($studentIds as $studentId) {
            if (! isset($existing[$studentId])) {
                HomeworkSubmission::create(['homework_id' => $homework->id, 'student_id' => $studentId, 'status' => 'pending']);
            }
        }
    }

    /**
     * @param  array<int|string, array{status: string, marks?: numeric-string|float|null, feedback?: string|null}>  $rows  keyed by student id
     */
    public function grade(Homework $homework, array $rows): void
    {
        DB::transaction(function () use ($homework, $rows) {
            $submissions = HomeworkSubmission::query()->where('homework_id', $homework->id)->get()->keyBy('student_id');

            foreach ($rows as $studentId => $row) {
                $submission = $submissions->get((int) $studentId);

                if ($submission === null) {
                    throw ValidationException::withMessages(['rows' => __('homework.not_in_class')]);
                }

                $marks = $row['marks'] ?? null;

                if ($marks !== null && $marks !== '' && $homework->max_marks !== null && (float) $marks > (float) $homework->max_marks) {
                    throw ValidationException::withMessages(["rows.{$studentId}.marks" => __('homework.marks_exceed', ['max' => $homework->max_marks])]);
                }

                $submission->status = $row['status'];
                $submission->marks = $marks === '' ? null : $marks;
                $submission->feedback = $row['feedback'] ?? null;

                if (in_array($row['status'], ['submitted', 'late', 'graded'], true)) {
                    $submission->submitted_at ??= now();
                }

                if ($row['status'] === 'graded') {
                    $submission->graded_by = Auth::guard('web')->id();
                    $submission->graded_at = now();
                }

                $submission->save();
            }

            $this->audit->tenant('homework.graded', $homework, new: ['rows' => count($rows)]);
        });
    }

    private function storeAttachment(Homework $homework, ?UploadedFile $file): void
    {
        if ($file === null) {
            return;
        }

        $path = $this->storage->path('homework/'.Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: $file->extension()));
        $this->storage->disk()->putFileAs(dirname($path), $file, basename($path));

        if ($homework->attachment_path && $this->storage->owns($homework->attachment_path)) {
            $this->storage->disk()->delete($homework->attachment_path);
        }

        $homework->attachment_path = $path;
        $homework->attachment_name = Str::limit($file->getClientOriginalName(), 180, '');
    }
}
