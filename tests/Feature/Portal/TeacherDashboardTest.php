<?php

namespace Tests\Feature\Portal;

use App\Modules\Notices\Models\Notice;
use App\Modules\Teaching\Models\ClassRoutine;
use Carbon\CarbonImmutable;
use Tests\Concerns\CreatesAcademicStructure;
use Tests\Concerns\CreatesTeachers;
use Tests\TenantTestCase;

class TeacherDashboardTest extends TenantTestCase
{
    use CreatesAcademicStructure, CreatesTeachers;

    public function test_dashboard_shows_only_the_teachers_own_day(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-06-08 09:00', 'Asia/Dhaka')); // a Monday
        $s = $this->academicStructure();
        [$user, $staff] = $this->teacher('Class Teacher', 'Rina');
        [, $other] = $this->teacher('Teacher', 'Other');
        $math = $this->subjectFor($s['session'], $s['class6'], 'Math');
        $this->assign($staff, $s['session'], $s['class6'], $s['sectionA']->id, null, 'class_teacher');
        $this->assign($staff, $s['session'], $s['class6'], $s['sectionB']->id, $math);

        $base = ['academic_session_id' => $s['session']->id, 'class_id' => $s['class6']->id, 'subject_id' => $math->id, 'day_of_week' => 1];
        ClassRoutine::create($base + ['section_id' => $s['sectionB']->id, 'staff_member_id' => $staff->id, 'starts_at' => '09:00', 'ends_at' => '09:40']);
        ClassRoutine::create($base + ['section_id' => $s['sectionA']->id, 'staff_member_id' => $other->id, 'starts_at' => '10:00', 'ends_at' => '10:40']);
        ClassRoutine::create(['day_of_week' => 2] + $base + ['section_id' => $s['sectionB']->id, 'staff_member_id' => $staff->id, 'starts_at' => '09:00', 'ends_at' => '09:40']);

        Notice::create(['title' => 'Staff meeting', 'body' => 'At 2pm', 'audiences' => ['staff'], 'status' => 'published']);
        Notice::create(['title' => 'For parents', 'body' => 'PTA', 'audiences' => ['parents'], 'status' => 'published']);

        $this->actingAs($user)->get(route('teacher.dashboard'))->assertOk()->assertInertia(fn ($p) => $p
            ->component('portal/teacher')
            ->where('teacher.name', 'Rina')
            ->has('periods', 1)->where('periods.0.section', 'B')
            ->has('attendance', 1)->where('attendance.0.section', 'A')->where('attendance.0.state', 'pending')
            ->has('classes', 2)
            ->has('notices', 1)->where('notices.0.title', 'Staff meeting')
            ->where('auth.area', 'teacher'));
    }

    public function test_only_staff_records_open_the_teacher_dashboard(): void
    {
        $this->academicStructure();
        $this->actingAs($this->ownerOf($this->school))->get(route('teacher.dashboard'))->assertForbidden();
        $this->actingAs($this->userWithRole('Teacher'))->get(route('teacher.dashboard'))->assertForbidden(); // no staff record
    }
}
