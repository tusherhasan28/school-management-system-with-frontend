<?php

namespace Tests\Feature\Teaching;

use App\Modules\People\Models\StaffMember;
use App\Modules\Teaching\Models\ClassRoutine;
use App\Modules\Teaching\Models\Holiday;
use App\Modules\Teaching\Models\TeacherAssignment;
use App\Modules\Setup\Services\SchoolSettings;
use Tests\Concerns\CreatesAcademicStructure;
use Tests\Concerns\CreatesTeachers;
use Tests\TenantTestCase;

class TimetableAndAssignmentTest extends TenantTestCase
{
    use CreatesAcademicStructure, CreatesTeachers;

    /** @var array<string, mixed> */
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = $this->academicStructure();
    }

    private function period(array $o = []): array
    {
        return $o + [
            'academic_session_id' => $this->s['session']->id, 'section_id' => $this->s['sectionA']->id,
            'subject_id' => $this->subjectFor($this->s['session'], $this->s['class6'], 'Math')->id,
            'day_of_week' => 7, 'starts_at' => '08:00', 'ends_at' => '08:45', 'room' => null, 'staff_member_id' => null,
        ];
    }

    public function test_assignments_follow_the_rules(): void
    {
        [, $staff] = $this->teacher();
        $math = $this->subjectFor($this->s['session'], $this->s['class6'], 'Math');
        $owner = $this->ownerOf($this->school);
        $base = ['academic_session_id' => $this->s['session']->id, 'staff_member_id' => $staff->id, 'class_id' => $this->s['class6']->id];

        $this->actingAs($owner)->post(route('assignments.store'), $base + ['assignment_type' => 'class_teacher'])->assertSessionHasErrors('section_id');
        $this->actingAs($owner)->post(route('assignments.store'), $base + ['assignment_type' => 'class_teacher', 'section_id' => $this->s['sectionA']->id])->assertSessionHasNoErrors();
        [, $other] = $this->teacher(name: 'Other');
        $this->actingAs($owner)->post(route('assignments.store'), ['staff_member_id' => $other->id] + $base + ['assignment_type' => 'class_teacher', 'section_id' => $this->s['sectionA']->id])->assertSessionHasErrors('section_id');

        $science = \App\Modules\Academics\Models\Subject::create(['name' => 'Science']); // not mapped to class 6
        $this->actingAs($owner)->post(route('assignments.store'), $base + ['assignment_type' => 'subject_teacher', 'subject_id' => $science->id])->assertSessionHasErrors('subject_id');
        $this->actingAs($owner)->post(route('assignments.store'), $base + ['assignment_type' => 'subject_teacher', 'subject_id' => $math->id])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('assignments.store'), $base + ['assignment_type' => 'subject_teacher', 'subject_id' => $math->id])->assertSessionHasErrors('staff_member_id');

        $clerk = StaffMember::create(['staff_type' => 'staff', 'employee_no' => 'S-9', 'full_name' => 'Clerk']);
        $this->actingAs($owner)->post(route('assignments.store'), ['staff_member_id' => $clerk->id] + $base + ['assignment_type' => 'subject_teacher', 'subject_id' => $math->id])->assertSessionHasErrors('staff_member_id');

        $this->assertSame(2, TeacherAssignment::query()->count());
    }

    public function test_timetable_blocks_section_teacher_and_room_clashes(): void
    {
        [, $teacher] = $this->teacher();
        $owner = $this->ownerOf($this->school);

        $this->actingAs($owner)->post(route('timetable.store'), $this->period(['staff_member_id' => $teacher->id, 'room' => '101']))->assertSessionHasNoErrors();
        // Same section overlapping
        $this->actingAs($owner)->post(route('timetable.store'), $this->period(['starts_at' => '08:30', 'ends_at' => '09:00']))->assertSessionHasErrors('starts_at');
        // Same teacher in another section at the same time
        $this->actingAs($owner)->post(route('timetable.store'), $this->period(['section_id' => $this->s['sectionB']->id, 'staff_member_id' => $teacher->id]))->assertSessionHasErrors('staff_member_id');
        // Same room
        $this->actingAs($owner)->post(route('timetable.store'), $this->period(['section_id' => $this->s['sectionB']->id, 'room' => '101']))->assertSessionHasErrors('room');
        // Back-to-back is fine; another day is fine
        $this->actingAs($owner)->post(route('timetable.store'), $this->period(['starts_at' => '08:45', 'ends_at' => '09:30', 'staff_member_id' => $teacher->id, 'room' => '101']))->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('timetable.store'), $this->period(['day_of_week' => 1, 'staff_member_id' => $teacher->id]))->assertSessionHasNoErrors();
        // End must be after start
        $this->actingAs($owner)->post(route('timetable.store'), $this->period(['day_of_week' => 2, 'starts_at' => '10:00', 'ends_at' => '09:00']))->assertSessionHasErrors('ends_at');

        $this->assertSame(3, ClassRoutine::query()->count());
        $this->assertSame($this->s['class6']->id, ClassRoutine::query()->first()->class_id, 'Class is derived from the section, not the form.');
    }

    public function test_teacher_sees_own_timetable_only(): void
    {
        [$user, $teacher] = $this->teacher();
        [, $other] = $this->teacher(name: 'Other');
        ClassRoutine::create($this->period(['staff_member_id' => $teacher->id, 'class_id' => $this->s['class6']->id]));
        ClassRoutine::create($this->period(['staff_member_id' => $other->id, 'class_id' => $this->s['class6']->id, 'section_id' => $this->s['sectionB']->id]));

        $this->actingAs($user)->get(route('timetable.mine'))->assertInertia(fn ($p) => $p->has('periods', 1)->where('periods.0.section', 'A'));
        $this->actingAs($user)->post(route('timetable.store'), $this->period(['day_of_week' => 3]))->assertForbidden();
    }

    public function test_calendar_settings_and_holidays_need_calendar_permission(): void
    {
        $owner = $this->ownerOf($this->school);

        $this->actingAs($owner)->put(route('calendar.settings'), ['weekend' => [5, 6], 'notify_absent' => true])->assertSessionHasNoErrors();
        $this->assertSame([5, 6], app(SchoolSettings::class)->get('calendar.weekend_days'));
        $this->assertTrue(app(SchoolSettings::class)->get('attendance.notify_absent'));

        $this->actingAs($owner)->post(route('calendar.holidays.store'), ['name' => 'Eid', 'start_date' => '2026-03-20', 'end_date' => '2026-03-18', 'holiday_type' => 'religious'])->assertSessionHasErrors('end_date');
        $this->actingAs($owner)->post(route('calendar.holidays.store'), ['name' => 'Eid', 'start_date' => '2026-03-18', 'end_date' => '2026-03-22', 'holiday_type' => 'religious'])->assertSessionHasNoErrors();

        $this->actingAs($this->userWithRole('Teacher'))->get(route('calendar.index', ['year' => 2026]))->assertOk()->assertInertia(fn ($p) => $p->has('holidays', 1)->where('can.manage', false));
        $this->actingAs($this->userWithRole('Teacher'))->post(route('calendar.holidays.store'), ['name' => 'X', 'start_date' => '2026-04-01', 'end_date' => '2026-04-01', 'holiday_type' => 'school'])->assertForbidden();
        $this->assertSame(1, Holiday::query()->count());
    }

    public function test_other_schools_records_cannot_be_used(): void
    {
        $beta = $this->provisionSchool('beta');
        [$betaTeacher, $betaRoutine, $betaHoliday] = $this->inSchool($beta, function () {
            $s = $this->academicStructure();
            [, $staff] = $this->teacher();
            $routine = ClassRoutine::create(['academic_session_id' => $s['session']->id, 'class_id' => $s['class6']->id, 'section_id' => $s['sectionA']->id,
                'subject_id' => $this->subjectFor($s['session'], $s['class6'], 'Math')->id, 'day_of_week' => 7, 'starts_at' => '08:00', 'ends_at' => '08:45']);

            return [$staff, $routine, Holiday::create(['name' => 'Beta Day', 'start_date' => '2026-05-01', 'end_date' => '2026-05-01'])];
        });
        $this->onSchool($this->school);
        $owner = $this->ownerOf($this->school);

        $this->actingAs($owner)->post(route('timetable.store'), $this->period(['staff_member_id' => $betaTeacher->id]))->assertSessionHasErrors('staff_member_id');
        $this->actingAs($owner)->put(route('timetable.update', $betaRoutine->id), $this->period())->assertNotFound();
        $this->actingAs($owner)->delete(route('timetable.destroy', $betaRoutine->id))->assertNotFound();
        $this->actingAs($owner)->delete(route('calendar.holidays.destroy', $betaHoliday->id))->assertNotFound();
        $this->actingAs($owner)->post(route('assignments.store'), ['academic_session_id' => $this->s['session']->id, 'staff_member_id' => $betaTeacher->id, 'class_id' => $this->s['class6']->id, 'assignment_type' => 'class_teacher', 'section_id' => $this->s['sectionA']->id])
            ->assertSessionHasErrors('staff_member_id');

        $this->assertTrue($this->inSchool($beta, fn () => ClassRoutine::query()->whereKey($betaRoutine->id)->exists()));
    }
}
