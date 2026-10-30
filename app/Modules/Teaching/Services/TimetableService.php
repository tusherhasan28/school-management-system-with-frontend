<?php

namespace App\Modules\Teaching\Services;

use App\Modules\People\Models\StaffMember;
use App\Modules\Teaching\Models\ClassRoutine;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Timetable periods with clash detection: a section, a teacher and a room
 * can each be in only one place at a time.
 */
class TimetableService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(array $data, ?ClassRoutine $routine = null): ClassRoutine
    {
        return DB::transaction(function () use ($data, $routine) {
            $this->assertNoClash($data, $routine);

            if (! empty($data['staff_member_id'])) {
                $teacher = StaffMember::query()->findOrFail($data['staff_member_id']);

                if ($teacher->staff_type !== 'teacher' || $teacher->status !== 'active') {
                    throw ValidationException::withMessages(['staff_member_id' => __('teaching.not_active_teacher')]);
                }
            }

            $old = $routine?->only(array_keys($data)) ?? [];
            $routine ??= new ClassRoutine;
            $routine->fill($data)->save();
            $this->audit->tenant($old ? 'timetable.updated' : 'timetable.created', $routine, $old, $data);

            return $routine;
        });
    }

    public function delete(ClassRoutine $routine): void
    {
        $this->audit->tenant('timetable.deleted', $routine, $routine->only(['section_id', 'subject_id', 'day_of_week', 'starts_at', 'ends_at']));
        $routine->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertNoClash(array $data, ?ClassRoutine $ignore): void
    {
        $overlapping = fn () => ClassRoutine::query()
            ->where('academic_session_id', $data['academic_session_id'])
            ->where('day_of_week', $data['day_of_week'])
            ->where('starts_at', '<', $data['ends_at'])
            ->where('ends_at', '>', $data['starts_at'])
            ->when($ignore, fn ($q) => $q->whereKeyNot($ignore->id))
            ->lockForUpdate();

        if ($overlapping()->where('section_id', $data['section_id'])->exists()) {
            throw ValidationException::withMessages(['starts_at' => __('teaching.clash_section')]);
        }

        if (! empty($data['staff_member_id']) && $overlapping()->where('staff_member_id', $data['staff_member_id'])->exists()) {
            throw ValidationException::withMessages(['staff_member_id' => __('teaching.clash_teacher')]);
        }

        if (! empty($data['room']) && $overlapping()->where('room', $data['room'])->exists()) {
            throw ValidationException::withMessages(['room' => __('teaching.clash_room')]);
        }
    }
}
