<?php

namespace Tests\Feature\Performance;

use App\Modules\Finance\Models\FeeCategory;
use App\Modules\Finance\Models\FeeStructure;
use App\Modules\Finance\Services\BillingService;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Notices\Models\Notice;
use App\Modules\People\Models\Student;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesAcademicStructure;
use Tests\TenantTestCase;

/**
 * Milestone 8 query review: the number of SQL queries behind the busiest
 * screens must not grow with the number of rows (no N+1). Each page is
 * measured with a small and a ten-times larger school.
 */
class QueryScalingTest extends TenantTestCase
{
    use CreatesAcademicStructure;

    /** @var array<string, mixed> */
    private array $s;

    private int $made = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00', 'Asia/Dhaka'));
        $this->s = $this->academicStructure();
        $tuition = FeeCategory::create(['name' => 'Tuition']);
        FeeStructure::create(['academic_session_id' => $this->s['session']->id, 'class_id' => $this->s['class6']->id, 'fee_category_id' => $tuition->id,
            'name' => 'Tuition', 'amount' => '1000.00', 'frequency' => 'monthly', 'start_period' => '2026-01', 'applies_to' => 'class']);
    }

    /** Add students (with guardian, invoice, payment, attendance) up to $total. */
    private function grow(int $total): void
    {
        $owner = $this->ownerOf($this->school);

        while ($this->made < $total) {
            $this->made++;
            $student = $this->makeStudent($this->s['session'], $this->s['class6'], $this->s['sectionA'], ['full_name' => 'Student '.$this->made], '0171'.str_pad((string) $this->made, 7, '0', STR_PAD_LEFT), (string) $this->made);
            Notice::create(['title' => 'Notice '.$this->made, 'body' => 'b', 'audiences' => ['public', 'parents'], 'status' => 'published']);
        }

        app(BillingService::class)->generate('2026-10', null, CarbonImmutable::parse('2026-10-10'), $owner);

        foreach (Student::query()->whereNotIn('id', \App\Modules\Finance\Models\FeePayment::query()->select('student_id'))->get() as $student) {
            app(PaymentService::class)->record($student, ['amount' => '500.00', 'method' => 'cash', 'idempotency_key' => 'k'.$student->id], null, $owner);
        }

        $marks = Student::query()->pluck('id')->mapWithKeys(fn ($id) => [$id => ['status' => 'present']])->all();
        app(\App\Modules\Attendance\Services\AttendanceService::class)->record($this->s['sectionA'], CarbonImmutable::today(), $marks);
    }

    private function queries(callable $request): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    }

    public function test_busy_screens_do_not_run_more_queries_for_more_rows(): void
    {
        $owner = $this->ownerOf($this->school);
        $pages = [
            'students' => fn () => $this->actingAs($owner)->get(route('students.index'))->assertOk(),
            'guardians' => fn () => $this->actingAs($owner)->get(route('guardians.index'))->assertOk(),
            'invoices' => fn () => $this->actingAs($owner)->get(route('fees.invoices.index'))->assertOk(),
            'payments' => fn () => $this->actingAs($owner)->get(route('fees.payments.index'))->assertOk(),
            'fees dashboard' => fn () => $this->actingAs($owner)->get(route('fees.dashboard'))->assertOk(),
            'attendance sheet' => fn () => $this->actingAs($owner)->get(route('attendance.take', ['section' => $this->s['sectionA']->id, 'date' => '2026-10-29']))->assertOk(),
            'dashboard' => fn () => $this->actingAs($owner)->get(route('dashboard'))->assertOk(),
            'notices admin' => fn () => $this->actingAs($owner)->get(route('notices.index'))->assertOk(),
            'public notices' => fn () => $this->get('/notices')->assertOk(),
        ];

        $this->grow(3);
        $small = array_map(fn ($p) => $this->queries($p), $pages);
        $this->grow(30);
        $large = array_map(fn ($p) => $this->queries($p), $pages);

        $growth = [];

        foreach ($pages as $name => $_) {
            // A couple of extra queries can come from pagination/chunking; a per-row query would add ~27+.
            if ($large[$name] - $small[$name] > 3) {
                $growth[] = "{$name}: {$small[$name]} → {$large[$name]} queries";
            }

            $this->assertLessThan(60, $large[$name], "{$name} runs {$large[$name]} queries");
        }

        $this->assertSame([], $growth, 'Query count grows with the number of rows (N+1)');
    }
}
