<?php

namespace Database\Seeders;

use App\Enums\PlatformRole;
use App\Enums\SchoolStatus;
use App\Models\PlatformUser;
use App\Models\School;
use App\Models\SchoolRegistration;
use App\Modules\Academics\Models\SchoolClass;
use App\Modules\Admissions\Services\AdmissionService;
use App\Modules\People\Services\StaffService;
use App\Modules\People\Services\StudentService;
use App\Modules\Setup\Services\SetupWizard;
use App\Services\Tenancy\SchoolProvisioner;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

/**
 * Demo data for local development only. Refuses to run in production.
 * All demo accounts use the password "password".
 */
class DatabaseSeeder extends Seeder
{
    public function run(SchoolProvisioner $provisioner, TenantContext $tenant): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Demo seed data is only allowed in local/testing environments.');
        }

        Notification::fake(); // no demo emails

        $admin = new PlatformUser(['name' => 'Platform Owner', 'email' => 'owner@'.config('tenancy.domain'), 'password' => 'password']);
        $admin->role = PlatformRole::Owner;
        $admin->email_verified_at = now();
        $admin->save();

        $dhaka = $provisioner->provision(
            ['slug' => 'dhaka-model', 'name' => 'Dhaka Model School', 'name_bn' => 'ঢাকা মডেল স্কুল', 'institution_type' => 'school', 'locale' => 'bn'],
            ['name' => 'Rahim Uddin', 'email' => 'principal@dhaka-model.test', 'password' => 'password'],
            SchoolStatus::Active,
        );

        $provisioner->provision(
            ['slug' => 'ctg-madrasa', 'name' => 'Chattogram Alia Madrasa', 'name_bn' => 'চট্টগ্রাম আলিয়া মাদ্রাসা', 'institution_type' => 'madrasa', 'locale' => 'bn'],
            ['name' => 'Karim Hossain', 'email' => 'principal@ctg-madrasa.test', 'password' => 'password'],
            SchoolStatus::Trial,
        );

        $tenant->run($dhaka, fn (School $school) => $this->seedDhaka($school));

        SchoolRegistration::create([
            'institution_name' => 'Sylhet Ideal Kindergarten', 'institution_name_bn' => 'সিলেট আইডিয়াল কিন্ডারগার্টেন',
            'requested_slug' => 'sylhet-ideal', 'institution_type' => 'kindergarten', 'contact_name' => 'Nasrin Akter',
            'email' => 'nasrin@sylhet-ideal.test', 'phone' => '01811000000', 'district' => 'Sylhet', 'approx_students' => 180,
        ]);
    }

    private function seedDhaka(School $school): void
    {
        $wizard = app(SetupWizard::class);
        $wizard->saveProfile(['name' => $school->name, 'name_bn' => $school->name_bn, 'institution_type' => 'school', 'locale' => 'bn', 'district' => 'Dhaka', 'phone' => '01700000000']);
        $session = $wizard->saveSession(['name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $preset = SetupWizard::classPreset('school');
        $wizard->saveClasses(array_map(fn ($c) => $c + ['sections' => ['A', 'B']], $preset['classes']), $preset['stage']);
        $wizard->saveSubjects(SetupWizard::subjectPreset('school'));
        $wizard->finish();

        $class6 = SchoolClass::query()->where('name', 'Class 6')->firstOrFail();
        $sectionA = $class6->sections()->where('name', 'A')->firstOrFail();

        $students = app(StudentService::class);
        $names = [
            ['Ayesha Rahman', 'আয়েশা রহমান', 'female', 'Mizanur Rahman', '01711111111'],
            ['Tanvir Hasan', 'তানভীর হাসান', 'male', 'Abdul Hasan', '01722222222'],
            ['Nusrat Jahan', 'নুসরাত জাহান', 'female', 'Mizanur Rahman', '01711111111'], // sibling of Ayesha
            ['Rafiul Islam', 'রফিউল ইসলাম', 'male', 'Shafiqul Islam', '01733333333'],
        ];

        foreach ($names as $i => [$name, $bn, $gender, $guardian, $phone]) {
            $students->create(
                ['full_name' => $name, 'name_bn' => $bn, 'gender' => $gender, 'date_of_birth' => '2014-0'.($i + 1).'-15'],
                [['full_name' => $guardian, 'phone' => $phone, 'relationship' => 'father']],
                ['academic_session_id' => $session->id, 'class_id' => $class6->id, 'section_id' => $sectionA->id, 'roll_no' => (string) ($i + 1), 'enrolled_at' => '2026-01-01'],
            );
        }

        $salma = app(StaffService::class)->create(['staff_type' => 'teacher', 'full_name' => 'Salma Begum', 'name_bn' => 'সালমা বেগম', 'gender' => 'female', 'designation' => 'Assistant Teacher', 'phone' => '01744444444', 'email' => 'salma@dhaka-model.test'], 'Class Teacher');
        // Demo only: activate the teacher login directly (normally via the emailed invitation).
        $salma->user->forceFill(['password' => 'password', 'status' => 'active', 'email_verified_at' => now()])->save();

        $this->seedOperations($session, $class6, $sectionA, $salma);

        app(AdmissionService::class)->submit([
            'applied_class_id' => $class6->id, 'applicant_name' => 'Imran Kabir', 'applicant_name_bn' => 'ইমরান কবির',
            'date_of_birth' => '2014-09-02', 'gender' => 'male', 'guardian_name' => 'Humayun Kabir', 'guardian_relationship' => 'father',
            'guardian_phone' => '01755555555', 'address' => 'Uttara, Dhaka',
        ]);
    }

    private function seedOperations(\App\Modules\Academics\Models\AcademicSession $session, SchoolClass $class6, \App\Modules\Academics\Models\Section $sectionA, \App\Modules\People\Models\StaffMember $salma): void
    {
        $subjects = \App\Modules\Academics\Models\Subject::query()->pluck('id', 'code');

        foreach ($subjects as $code => $id) {
            \App\Modules\Academics\Models\ClassSubject::create(['academic_session_id' => $session->id, 'class_id' => $class6->id, 'subject_id' => $id, 'full_marks' => 100, 'pass_marks' => 33]);
        }

        $assignments = app(\App\Modules\Teaching\Services\AssignmentService::class);
        $assignments->assign(['academic_session_id' => $session->id, 'staff_member_id' => $salma->id, 'class_id' => $class6->id, 'section_id' => $sectionA->id, 'subject_id' => null, 'assignment_type' => 'class_teacher']);
        $assignments->assign(['academic_session_id' => $session->id, 'staff_member_id' => $salma->id, 'class_id' => $class6->id, 'section_id' => null, 'subject_id' => $subjects['MATH'], 'assignment_type' => 'subject_teacher']);

        $timetable = app(\App\Modules\Teaching\Services\TimetableService::class);
        $slots = [['08:00', '08:45'], ['08:45', '09:30'], ['09:45', '10:30'], ['10:30', '11:15']];
        $order = ['BAN', 'ENG', 'MATH', 'SCI'];

        foreach ([7, 1, 2, 3, 4] as $day) { // Sunday–Thursday
            foreach ($slots as $i => [$start, $end]) {
                $code = $order[($i + $day) % 4];
                $timetable->save([
                    'academic_session_id' => $session->id, 'class_id' => $class6->id, 'section_id' => $sectionA->id,
                    'subject_id' => $subjects[$code], 'staff_member_id' => $code === 'MATH' ? $salma->id : null,
                    'day_of_week' => $day, 'starts_at' => $start, 'ends_at' => $end, 'room' => '6A',
                ]);
            }
        }

        \App\Modules\Teaching\Models\Holiday::create(['name' => 'Victory Day', 'name_bn' => 'বিজয় দিবস', 'start_date' => '2026-12-16', 'end_date' => '2026-12-16', 'holiday_type' => 'public']);
        \App\Modules\Teaching\Models\Holiday::create(['name' => 'International Mother Language Day', 'name_bn' => 'আন্তর্জাতিক মাতৃভাষা দিবস', 'start_date' => '2026-02-21', 'end_date' => '2026-02-21', 'holiday_type' => 'public']);

        app(\App\Modules\Homework\Services\HomeworkService::class)->create([
            'academic_session_id' => $session->id, 'class_id' => $class6->id, 'section_id' => $sectionA->id, 'subject_id' => $subjects['MATH'],
            'staff_member_id' => $salma->id, 'title' => 'Fractions — exercise 3.2', 'instructions' => 'Solve questions 1 to 10 in your notebook.',
            'assigned_on' => now('Asia/Dhaka')->toDateString(), 'due_at' => now('Asia/Dhaka')->addDays(3)->setTime(9, 0), 'max_marks' => 10, 'status' => 'published',
        ]);
    }
}
