<?php

namespace Tests\Feature\Teaching;

use App\Modules\Teaching\Models\ClassRoutine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAcademicStructure;
use Tests\Concerns\CreatesTeachers;
use Tests\TenantTestCase;

/**
 * Teachers only reach the classes, sections and subjects they are assigned to.
 */
class TeacherAccessTest extends TenantTestCase
{
    use CreatesAcademicStructure, CreatesTeachers;

    /** @var array<string, mixed> */
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-10 09:00:00'); // Tuesday
        $this->s = $this->academicStructure();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function students(): array
    {
        $a = $this->makeStudent($this->s['session'], $this->s['class6'], $this->s['sectionA'], ['full_name' => 'In 6A'], roll: '1');
        $b = $this->makeStudent($this->s['session'], $this->s['class6'], $this->s['sectionB'], ['full_name' => 'In 6B'], '01722000000', '1');
        $c = $this->makeStudent($this->s['session'], $this->s['class7'], $this->s['class7A'], ['full_name' => 'In 7A'], '01733000000', '1');
        DB::table('student_enrollments')->update(['enrolled_at' => '2026-01-01']);

        return [$a, $b, $c];
    }

    public function test_class_teacher_takes_daily_attendance_only_for_their_section(): void
    {
        [$a, $b] = $this->students();
        [$user, $staff] = $this->teacher('Class Teacher');
        $this->assign($staff, $this->s['session'], $this->s['class6'], $this->s['sectionA']->id, null, 'class_teacher');

        $this->actingAs($user)->post(route('attendance.store'), ['section_id' => $this->s['sectionA']->id, 'date' => '2026-03-10', 'marks' => [$a->id => ['status' => 'present']]])
            ->assertSessionHasNoErrors();

        $this->actingAs($user)->post(route('attendance.store'), ['section_id' => $this->s['sectionB']->id, 'date' => '2026-03-10', 'marks' => [$b->id => ['status' => 'present']]])
            ->assertForbidden();
        $this->actingAs($user)->get(route('attendance.take', ['section' => $this->s['sectionB']->id]))->assertForbidden();

        $this->actingAs($user)->get(route('attendance.index'))
            ->assertInertia(fn ($p) => $p->has('sections', 1)->where('sections.0.id', $this->s['sectionA']->id)->where('sections.0.canTake', true));
    }

    public function test_subject_teacher_takes_attendance_for_their_period_but_not_daily(): void
    {
        [$a] = $this->students();
        $math = $this->subjectFor($this->s['session'], $this->s['class6'], 'Math');
        $english = $this->subjectFor($this->s['session'], $this->s['class6'], 'English');
        [$user, $staff] = $this->teacher('Teacher');
        $this->assign($staff, $this->s['session'], $this->s['class6'], null, $math);

        $mathPeriod = ClassRoutine::create(['academic_session_id' => $this->s['session']->id, 'class_id' => $this->s['class6']->id, 'section_id' => $this->s['sectionA']->id, 'subject_id' => $math->id, 'day_of_week' => 2, 'starts_at' => '08:00', 'ends_at' => '08:45']);
        $englishPeriod = ClassRoutine::create(['academic_session_id' => $this->s['session']->id, 'class_id' => $this->s['class6']->id, 'section_id' => $this->s['sectionA']->id, 'subject_id' => $english->id, 'day_of_week' => 2, 'starts_at' => '08:45', 'ends_at' => '09:30']);

        $marks = [$a->id => ['status' => 'present']];
        $this->actingAs($user)->post(route('attendance.store'), ['section_id' => $this->s['sectionA']->id, 'date' => '2026-03-10', 'marks' => $marks])->assertForbidden();
        $this->actingAs($user)->post(route('attendance.store'), ['section_id' => $this->s['sectionA']->id, 'date' => '2026-03-10', 'routine_id' => $mathPeriod->id, 'marks' => $marks])->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('attendance.store'), ['section_id' => $this->s['sectionA']->id, 'date' => '2026-03-10', 'routine_id' => $englishPeriod->id, 'marks' => $marks])->assertForbidden();
    }

    public function test_teacher_sees_only_students_of_assigned_sections(): void
    {
        [$a, $b, $c] = $this->students();
        [$user, $staff] = $this->teacher('Teacher');
        $this->assign($staff, $this->s['session'], $this->s['class6'], $this->s['sectionA']->id, $this->subjectFor($this->s['session'], $this->s['class6'], 'Math'));

        $this->actingAs($user)->get(route('students.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->has('students.data', 1)->where('students.data.0.full_name', 'In 6A'));

        $this->actingAs($user)->get(route('students.show', $a))->assertOk();
        $this->actingAs($user)->get(route('students.show', $b))->assertForbidden();
        $this->actingAs($user)->get(route('students.show', $c))->assertForbidden();
        $this->actingAs($user)->get(route('students.edit', $a))->assertForbidden();
    }

    public function test_class_wide_assignment_covers_all_sections_of_that_class_only(): void
    {
        [$a, $b, $c] = $this->students();
        [$user, $staff] = $this->teacher('Teacher');
        $this->assign($staff, $this->s['session'], $this->s['class6'], null, $this->subjectFor($this->s['session'], $this->s['class6'], 'Math'));

        $this->actingAs($user)->get(route('students.index'))->assertInertia(fn ($p) => $p->has('students.data', 2));
        $this->actingAs($user)->get(route('students.show', $c))->assertForbidden();
    }

    public function test_teacher_without_assignments_has_no_student_or_section_access(): void
    {
        $this->students();
        [$user] = $this->teacher('Class Teacher');

        $this->actingAs($user)->get(route('students.index'))->assertForbidden();
        $this->actingAs($user)->get(route('attendance.index'))->assertInertia(fn ($p) => $p->has('sections', 0));
    }

    public function test_assignments_from_last_session_do_not_grant_access(): void
    {
        [$a] = $this->students();
        $old = \App\Modules\Academics\Models\AcademicSession::create(['name' => '2025', 'start_date' => '2025-01-01', 'end_date' => '2025-12-31']);
        [$user, $staff] = $this->teacher('Class Teacher');
        $this->assign($staff, $old, $this->s['class6'], $this->s['sectionA']->id, null, 'class_teacher');

        $this->actingAs($user)->get(route('students.show', $a))->assertForbidden();
        $this->actingAs($user)->post(route('attendance.store'), ['section_id' => $this->s['sectionA']->id, 'date' => '2026-03-10', 'marks' => [$a->id => ['status' => 'present']]])->assertForbidden();
    }

    public function test_disabled_staff_record_removes_teacher_access(): void
    {
        [$a] = $this->students();
        [$user, $staff] = $this->teacher('Class Teacher');
        $this->assign($staff, $this->s['session'], $this->s['class6'], $this->s['sectionA']->id, null, 'class_teacher');
        $staff->update(['status' => 'inactive']);

        $this->actingAs($user)->get(route('students.show', $a))->assertForbidden();
    }

    public function test_principal_sees_all_sections(): void
    {
        $this->students();

        $this->actingAs($this->userWithRole('Principal'))->get(route('attendance.index'))
            ->assertInertia(fn ($p) => $p->has('sections', 3)->where('sections.0.canTake', true)
                ->where('sections.0.id', $this->s['sectionA']->id)
                ->where('sections.1.id', $this->s['sectionB']->id)
                ->where('sections.2.id', $this->s['class7A']->id));
    }
}
