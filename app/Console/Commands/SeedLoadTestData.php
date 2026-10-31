<?php

namespace App\Console\Commands;

use App\Enums\SchoolStatus;
use App\Models\School;
use App\Models\User;
use App\Modules\Academics\Models\Section;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Finance\Models\FeeCategory;
use App\Modules\Finance\Models\FeeStructure;
use App\Modules\Finance\Services\BillingService;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Notices\Models\Notice;
use App\Modules\People\Models\Student;
use App\Modules\People\Services\StudentService;
use App\Modules\Portal\Services\PortalAccounts;
use App\Modules\Setup\Services\SetupWizard;
use App\Modules\Teaching\Services\SchoolCalendar;
use App\Services\Tenancy\SchoolProvisioner;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Builds a large demo school for load tests (local/testing only): thousands
 * of students with guardians, months of daily attendance, invoices and
 * payments, notices, and one parent portal login.
 */
class SeedLoadTestData extends Command
{
    protected $signature = 'ops:load-dataset {--slug=load-test} {--students=1500} {--days=60}';

    protected $description = 'Create a large demo school for load testing (never in production)';

    public function handle(SchoolProvisioner $provisioner, TenantContext $tenant): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Load-test data is only allowed in local/testing environments.');

            return self::FAILURE;
        }

        Notification::fake();
        $slug = (string) $this->option('slug');
        $students = (int) $this->option('students');
        $days = (int) $this->option('days');

        if (School::query()->where('slug', $slug)->exists()) {
            $this->error("School {$slug} already exists.");

            return self::FAILURE;
        }

        $school = $provisioner->provision(['slug' => $slug, 'name' => 'Load Test High School', 'institution_type' => 'school', 'locale' => 'bn'],
            ['name' => 'Load Owner', 'email' => "owner@{$slug}.test", 'password' => 'password'], SchoolStatus::Active);

        $tenant->run($school, function () use ($students, $days, $slug) {
            $owner = User::query()->firstOrFail();
            $owner->forceFill(['email_verified_at' => now()])->save();
            $wizard = app(SetupWizard::class);
            $session = $wizard->saveSession(['name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
            $preset = SetupWizard::classPreset('school');
            $wizard->saveClasses(array_map(fn ($c) => $c + ['sections' => ['A', 'B']], $preset['classes']), $preset['stage']);
            $wizard->saveSubjects(SetupWizard::subjectPreset('school'));
            $wizard->finish();

            $sections = Section::query()->with('schoolClass')->orderBy('id')->get();
            $service = app(StudentService::class);
            $bar = $this->output->createProgressBar($students);
            $this->line('Students…');

            for ($i = 1; $i <= $students; $i++) {
                $section = $sections[$i % $sections->count()];
                // Every third student is a sibling of the one before (shared guardian phone).
                $family = $i % 3 === 0 ? $i - 1 : $i;
                $service->create(
                    ['full_name' => 'Student '.$i, 'gender' => $i % 2 ? 'male' : 'female', 'admission_date' => '2026-01-01'],
                    [['full_name' => 'Guardian '.$family, 'phone' => '019'.str_pad((string) $family, 8, '0', STR_PAD_LEFT), 'relationship' => 'father']],
                    ['academic_session_id' => $session->id, 'class_id' => $section->class_id, 'section_id' => $section->id, 'roll_no' => (string) intdiv($i, $sections->count()) + 1, 'enrolled_at' => '2026-01-01'],
                );
                $bar->advance();
            }

            $bar->finish();
            $this->newLine();
            $this->line('Attendance…');
            $calendar = app(SchoolCalendar::class);
            $attendance = app(AttendanceService::class);
            $day = CarbonImmutable::parse('2026-10-29');
            $taken = 0;

            while ($taken < $days) {
                if (! $calendar->isWeekend($day)) {
                    foreach ($sections as $section) {
                        $ids = Student::query()->whereHas('enrollments', fn ($q) => $q->where('section_id', $section->id))->pluck('id');
                        $attendance->record($section, $day, $ids->mapWithKeys(fn ($id) => [$id => ['status' => ($id + $taken) % 17 === 0 ? 'absent' : 'present']])->all());
                    }
                    $taken++;
                }

                $day = $day->subDay();
            }

            $this->line('Fees…');
            $category = FeeCategory::create(['name' => 'Tuition']);
            FeeStructure::create(['academic_session_id' => $session->id, 'class_id' => null, 'fee_category_id' => $category->id,
                'name' => 'Tuition', 'amount' => '1200.00', 'frequency' => 'monthly', 'start_period' => '2026-01', 'applies_to' => 'class']);

            foreach (['2026-08', '2026-09', '2026-10'] as $period) {
                app(BillingService::class)->generate($period, null, CarbonImmutable::parse($period.'-10'), $owner);
            }

            $payments = app(PaymentService::class);
            Student::query()->orderBy('id')->each(function (Student $s) use ($payments, $owner) {
                if ($s->id % 2 === 0) {
                    $payments->record($s, ['amount' => '2400.00', 'method' => 'cash', 'idempotency_key' => 'load-'.$s->id], null, $owner);
                }
            });

            for ($n = 1; $n <= 60; $n++) {
                Notice::create(['title' => 'Notice '.$n, 'body' => 'Load test notice '.$n, 'audiences' => ['public', 'parents', 'students'], 'status' => 'published']);
            }

            $guardian = Student::query()->orderBy('id')->firstOrFail()->guardians()->firstOrFail();
            $guardian->update(['email' => "parent@{$slug}.test"]);
            app(PortalAccounts::class)->inviteGuardian($guardian->fresh())->forceFill(['password' => 'password', 'status' => 'active', 'email_verified_at' => now()])->save();
        });

        $this->info("Done: {$slug} (owner@{$slug}.test / parent@{$slug}.test, password: password)");

        return self::SUCCESS;
    }
}
