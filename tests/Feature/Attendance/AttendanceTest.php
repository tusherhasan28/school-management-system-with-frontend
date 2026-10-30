<?php

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Models\AttendanceCorrection;
use App\Modules\Attendance\Models\AttendanceSession;
use App\Modules\Attendance\Models\StudentAttendance;
use App\Modules\Attendance\Notifications\StudentAbsent;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\People\Models\Student;
use App\Modules\Setup\Services\SchoolSettings;
use App\Modules\Teaching\Models\ClassRoutine;
use App\Modules\Teaching\Models\Holiday;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesAcademicStructure;
use Tests\Concerns\CreatesTeachers;
use Tests\TenantTestCase;

class AttendanceTest extends TenantTestCase
{
    use CreatesAcademicStructure, CreatesTeachers;

    /** @var array<string, mixed> */
    private array $s;

    /** @var list<Student> */
    private array $kids;

    private const TUESDAY = '2026-03-10';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-10 09:00:00'); // Tuesday in Asia/Dhaka (03:00 UTC)
        $this->s = $this->academicStructure();
        // enrolled before the test dates
        $this->kids = collect(['Ayesha', 'Tanvir', 'Nusrat'])->map(fn ($n, $i) => $this->makeStudent($this->s['session'], $this->s['class6'], $this->s['sectionA'], ['full_name' => $n], '0171100000'.$i, (string) ($i + 1)))->all();
        DB::table('student_enrollments')->update(['enrolled_at' => '2026-01-01']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function marks(array $statuses): array
    {
        $out = [];
        foreach ($this->kids as $i => $kid) {
            if (isset($statuses[$i])) {
                $out[$kid->id] = ['status' => $statuses[$i]];
            }
        }

        return $out;
    }

    private function submitMarks(array $statuses, bool $finalize = false, string $date = self::TUESDAY, ?int $routine = null, $user = null)
    {
        return $this->actingAs($user ?? $this->ownerOf($this->school))->post(route('attendance.store'), [
            'section_id' => $this->s['sectionA']->id, 'date' => $date, 'routine_id' => $routine,
            'marks' => $this->marks($statuses), 'finalize' => $finalize,
        ]);
    }

    public function test_attendance_is_saved_then_finalized(): void
    {
        $this->submitMarks(['present', 'absent'])->assertSessionHasNoErrors();
        $this->submitMarks(['present', 'absent', 'late'], finalize: true)->assertSessionHasNoErrors();

        $session = AttendanceSession::query()->sole();
        $this->assertTrue($session->isFinalized());
        $this->assertSame(3, $session->marks()->count());
        $this->assertSame('daily', $session->period_key);
        $this->assertDatabaseHas('activity_logs', ['action' => 'attendance.finalized', 'subject_id' => $session->id]);
    }

    public function test_duplicate_attendance_for_the_same_section_and_day_is_impossible(): void
    {
        $this->submitMarks(['present']);
        $this->submitMarks(['present', 'absent']);

        $this->assertSame(1, AttendanceSession::query()->count());
        $this->assertSame(2, StudentAttendance::query()->count(), 'Re-saving updates marks instead of duplicating.');

        // The database enforces it too.
        $existing = AttendanceSession::query()->sole();
        $this->expectException(QueryException::class);
        DB::table('attendance_sessions')->insert([
            'school_id' => $this->school->id, 'academic_session_id' => $this->s['session']->id, 'class_id' => $this->s['class6']->id,
            'section_id' => $this->s['sectionA']->id, 'attendance_date' => self::TUESDAY, 'period_key' => 'daily',
        ]);
        unset($existing);
    }

    public function test_one_mark_per_student_is_enforced_by_the_database(): void
    {
        $this->submitMarks(['present']);
        $session = AttendanceSession::query()->sole();

        $this->expectException(QueryException::class);
        DB::table('student_attendance')->insert(['school_id' => $this->school->id, 'attendance_session_id' => $session->id, 'student_id' => $this->kids[0]->id, 'status' => 'absent']);
    }

    public function test_finalizing_requires_every_student_to_be_marked(): void
    {
        $this->submitMarks(['present', 'absent'], finalize: true)->assertSessionHasErrors('attendance');
        $this->assertFalse(AttendanceSession::query()->sole()->isFinalized());
    }

    public function test_students_outside_the_roster_are_rejected(): void
    {
        $other = $this->makeStudent($this->s['session'], $this->s['class6'], $this->s['sectionB'], guardianPhone: '01799999999');

        $this->actingAs($this->ownerOf($this->school))->post(route('attendance.store'), [
            'section_id' => $this->s['sectionA']->id, 'date' => self::TUESDAY,
            'marks' => [$other->id => ['status' => 'present']],
        ])->assertSessionHasErrors('marks');

        $this->assertSame(0, StudentAttendance::query()->count());
    }

    public function test_finalized_attendance_changes_only_through_audited_corrections(): void
    {
        $this->submitMarks(['present', 'present', 'present'], finalize: true);
        $this->submitMarks(['absent', 'present', 'present'])->assertSessionHasErrors('attendance');

        $mark = StudentAttendance::query()->where('student_id', $this->kids[0]->id)->sole();
        $owner = $this->ownerOf($this->school);

        $this->actingAs($owner)->post(route('attendance.correct', $mark), ['status' => 'absent'])->assertSessionHasErrors('reason');
        $this->actingAs($owner)->post(route('attendance.correct', $mark), ['status' => 'absent', 'reason' => 'Left after first period'])->assertSessionHasNoErrors();

        $this->assertSame('absent', $mark->fresh()->status);
        $correction = AttendanceCorrection::query()->sole();
        $this->assertSame(['present', 'absent', 'Left after first period', $owner->id], [$correction->old_status, $correction->new_status, $correction->reason, $correction->corrected_by]);

        // Corrections are append-only.
        $this->assertFalse($correction->update(['reason' => 'tampered']));
        $this->assertSame('Left after first period', $correction->fresh()->reason);
    }

    public function test_only_class_teachers_and_full_access_roles_can_correct(): void
    {
        [$subjectTeacher, $staff] = $this->teacher('Teacher');
        $this->assign($staff, $this->s['session'], $this->s['class6'], null, $this->subjectFor($this->s['session'], $this->s['class6'], 'Math'));
        $this->submitMarks(['present', 'present', 'present'], finalize: true);
        $mark = StudentAttendance::query()->firstOrFail();

        $this->actingAs($subjectTeacher)->post(route('attendance.correct', $mark), ['status' => 'absent', 'reason' => 'x x x'])->assertForbidden();
        $this->actingAs($this->userWithRole('Principal'))->post(route('attendance.correct', $mark), ['status' => 'leave', 'reason' => 'Medical'])->assertSessionHasNoErrors();
    }

    public function test_weekends_holidays_and_future_dates_are_refused(): void
    {
        $this->submitMarks(['present'], date: '2026-03-13')->assertSessionHasErrors('date'); // Friday
        Holiday::create(['name' => 'Independence Day', 'start_date' => '2026-03-26', 'end_date' => '2026-03-26', 'holiday_type' => 'public']);
        Carbon::setTestNow('2026-03-30 09:00:00');
        $this->submitMarks(['present'], date: '2026-03-26')->assertSessionHasErrors('date');
        $this->submitMarks(['present'], date: '2026-04-02')->assertSessionHasErrors('date'); // future

        // Weekend is a school setting: make Friday a school day and Saturday off.
        app(SchoolSettings::class)->put('calendar.weekend_days', [6]);
        $this->submitMarks(['present', 'present', 'present'], date: '2026-03-27')->assertSessionHasNoErrors(); // Friday
        $this->assertSame(0, AttendanceSession::query()->whereDate('attendance_date', '2026-03-26')->count());
    }

    public function test_monthly_summary_and_rate(): void
    {
        $service = app(AttendanceService::class);
        $days = ['2026-03-01' => ['present', 'absent', 'late'], '2026-03-02' => ['half_day', 'absent', 'present'], '2026-03-03' => ['leave', 'present', 'present']];

        foreach ($days as $date => $statuses) {
            $session = $service->record($this->s['sectionA'], CarbonImmutable::parse($date), $this->marks($statuses));
            $this->actingAs($this->ownerOf($this->school));
            $service->finalize($session);
        }

        $summary = collect($service->summary($this->s['sectionA'], CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31')))->keyBy('student_id');

        // Ayesha: present + ½ + leave → (1 + 0.5) / 2 = 75%
        $this->assertEquals(['present' => 1, 'half_day' => 1, 'leave' => 1, 'rate' => 75.0], array_intersect_key($summary[$this->kids[0]->id], array_flip(['present', 'half_day', 'leave', 'rate'])));
        // Tanvir: 2 absent, 1 present → 33.3%
        $this->assertSame(33.3, $summary[$this->kids[1]->id]['rate']);
        // Nusrat: late counts as attended → 100%
        $this->assertSame(100.0, $summary[$this->kids[2]->id]['rate']);

        $this->actingAs($this->ownerOf($this->school))
            ->get(route('attendance.report', ['section' => $this->s['sectionA']->id, 'month' => '2026-03']))
            ->assertInertia(fn ($p) => $p->component('app/attendance/report')->has('rows', 3)->where('rows.1.rate', 33.3));
    }

    public function test_guardians_are_emailed_about_absences_when_enabled(): void
    {
        Notification::fake();
        config(['queue.default' => 'sync']);
        $this->kids[1]->guardians()->first()->update(['email' => 'tanvir.parent@example.com']);

        $this->submitMarks(['present', 'absent', 'present'], finalize: true);
        Notification::assertNothingSent();

        app(SchoolSettings::class)->put('attendance.notify_absent', true);
        Carbon::setTestNow('2026-03-11 09:00:00');
        $this->submitMarks(['present', 'absent', 'present'], finalize: true, date: '2026-03-11');

        Notification::assertSentOnDemand(StudentAbsent::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'tanvir.parent@example.com' && $n->student === 'Tanvir');
        Notification::assertSentOnDemandTimes(StudentAbsent::class, 1);
    }

    public function test_period_attendance_is_separate_from_daily_and_must_match_the_day(): void
    {
        $math = $this->subjectFor($this->s['session'], $this->s['class6'], 'Math');
        $routine = ClassRoutine::create(['academic_session_id' => $this->s['session']->id, 'class_id' => $this->s['class6']->id, 'section_id' => $this->s['sectionA']->id,
            'subject_id' => $math->id, 'day_of_week' => 2, 'starts_at' => '09:00', 'ends_at' => '09:45']); // Tuesday

        $this->submitMarks(['present', 'present', 'present'])->assertSessionHasNoErrors();
        $this->submitMarks(['present', 'absent', 'present'], routine: $routine->id)->assertSessionHasNoErrors();
        $this->assertSame(['daily', 'routine:'.$routine->id], AttendanceSession::query()->orderBy('id')->pluck('period_key')->all());

        Carbon::setTestNow('2026-03-11 09:00:00'); // Wednesday
        $this->submitMarks(['present'], date: '2026-03-11', routine: $routine->id)->assertSessionHasErrors('period');
    }

    public function test_other_schools_attendance_is_unreachable(): void
    {
        $this->submitMarks(['present', 'present', 'present'], finalize: true);
        $mark = StudentAttendance::query()->firstOrFail();

        $beta = $this->provisionSchool('beta');
        $this->flushSession();
        $betaOwner = $this->ownerOf($beta);

        $this->actingAs($betaOwner)->post($this->schoolUrl($beta, '/app/attendance/marks/'.$mark->id.'/correct'), ['status' => 'absent', 'reason' => 'hijack'])->assertNotFound();
        $this->actingAs($betaOwner)->get($this->schoolUrl($beta, '/app/attendance/report?section='.$this->s['sectionA']->id))->assertNotFound();
        $this->actingAs($betaOwner)->post($this->schoolUrl($beta, '/app/attendance'), [
            'section_id' => $this->s['sectionA']->id, 'date' => self::TUESDAY, 'marks' => [$this->kids[0]->id => ['status' => 'absent']],
        ])->assertSessionHasErrors('section_id');

        $this->assertSame('present', $this->inSchool($this->school, fn () => StudentAttendance::query()->find($mark->id)->status));
    }
}
