<?php

namespace Tests\Feature\Tenancy;

use App\Models\User;
use App\Modules\Attendance\Models\StudentAttendance;
use App\Modules\Exams\Models\ExamMark;
use App\Modules\People\Models\StudentGuardian;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesFullTenantDataset;
use Tests\TestCase;

/**
 * Ids of another school put into request bodies (form fields and array
 * keys) are rejected; nothing is linked to or written into school B.
 */
class BodyIdIsolationTest extends TestCase
{
    use CreatesFullTenantDataset, RefreshDatabase;

    public function test_foreign_ids_in_bodies_are_rejected(): void
    {
        Storage::fake('local');
        Notification::fake();
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-29 10:00', 'Asia/Dhaka'));
        $alpha = $this->provisionSchool('alpha');
        $beta = $this->provisionSchool('beta');
        $a = $this->fullDataset($alpha);
        $b = $this->fullDataset($beta);
        $betaRows = fn () => DB::table('student_attendance')->where('school_id', $beta->id)->count().'/'.DB::table('fee_payment_allocations')->where('school_id', $beta->id)->count()
            .'/'.DB::table('exam_marks')->where('school_id', $beta->id)->count().'/'.DB::table('student_guardians')->where('school_id', $beta->id)->count();
        $before = $betaRows();

        $this->onSchool($alpha);
        $owner = $this->inSchool($alpha, fn () => User::query()->findOrFail($a['owner']));
        $post = fn (string $route, array $params, array $body) => $this->actingAs($owner)->post(route($route, $params), $body);

        // Plain id fields
        $post('students.store', [], ['full_name' => 'X', 'gender' => 'male', 'academic_session_id' => $b['session'], 'class_id' => $b['class'],
            'guardians' => [['full_name' => 'G', 'phone' => '01712345678', 'relationship' => 'father']]])->assertSessionHasErrors(['academic_session_id', 'class_id']);
        $post('timetable.store', [], ['academic_session_id' => $a['session'], 'section_id' => $b['section'], 'subject_id' => $b['subject'], 'staff_member_id' => $b['staff'],
            'day_of_week' => 2, 'starts_at' => '10:00', 'ends_at' => '10:40'])->assertSessionHasErrors(['section_id', 'subject_id', 'staff_member_id']);
        $post('assignments.store', [], ['academic_session_id' => $a['session'], 'staff_member_id' => $b['staff'], 'class_id' => $a['class'], 'assignment_type' => 'class_teacher'])
            ->assertSessionHasErrors('staff_member_id');
        $post('homework.store', [], ['class_id' => $a['class'], 'subject_id' => $b['subject'], 'title' => 'T', 'assigned_on' => '2026-10-29'])->assertSessionHasErrors('subject_id');
        $post('exams.store', [], ['name' => 'E', 'academic_session_id' => $a['session'], 'grading_scale_id' => $b['scale'], 'starts_on' => '2026-11-01'])->assertSessionHasErrors('grading_scale_id');
        $post('students.guardians.store', ['student' => $a['student']], ['guardian_id' => $b['guardian'], 'relationship' => 'father'])->assertSessionHasErrors('guardian_id');
        $post('promotions.store', [], ['from_session_id' => $a['session'], 'to_session_id' => $a['session'], 'to_class_id' => $a['class'], 'student_ids' => [$b['student']], 'action' => 'promote'])
            ->assertSessionHasErrors('student_ids.0');
        $post('messages.send', [], ['audience' => 'guardians_class', 'class_id' => $b['class'], 'channels' => ['mail'], 'message' => 'x'])->assertSessionHasErrors('class_id');
        $post('website.menu.store', [], ['location' => 'header', 'label' => 'x', 'link_type' => 'page', 'website_page_id' => $b['page']])->assertSessionHasErrors('website_page_id');

        // Ids used as array keys
        $this->actingAs($owner)->post(route('attendance.store'), ['section_id' => $a['section'], 'date' => '2026-10-29', 'marks' => [$b['student'] => ['status' => 'present']]])
            ->assertSessionHasErrors();
        $this->actingAs($owner)->post(route('fees.students.payments.store', $a['student']), ['amount' => '100.00', 'method' => 'cash', 'idempotency_key' => str_repeat('a', 20),
            'allocations' => [$b['invoice'] => '100.00']])->assertSessionHasErrors();
        $this->actingAs($owner)->post(route('marks.save', $a['sheet']), ['rows' => [$b['student'] => ['status' => 'absent']]])->assertSessionHasErrors();

        $this->assertSame($before, $betaRows(), 'school B rows were written');
        $this->assertSame(0, StudentGuardian::query()->withoutGlobalScopes()->where('school_id', $alpha->id)->where('guardian_id', $b['guardian'])->count());
        $this->assertSame(0, StudentAttendance::query()->withoutGlobalScopes()->where('student_id', $b['student'])->where('school_id', $alpha->id)->count());
        $this->assertSame(0, ExamMark::query()->withoutGlobalScopes()->where('student_id', $b['student'])->where('school_id', $alpha->id)->count());
    }
}
