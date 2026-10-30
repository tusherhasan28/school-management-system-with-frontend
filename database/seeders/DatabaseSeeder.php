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
                ['academic_session_id' => $session->id, 'class_id' => $class6->id, 'section_id' => $sectionA->id, 'roll_no' => (string) ($i + 1)],
            );
        }

        app(StaffService::class)->create(['staff_type' => 'teacher', 'full_name' => 'Salma Begum', 'name_bn' => 'সালমা বেগম', 'gender' => 'female', 'designation' => 'Assistant Teacher', 'phone' => '01744444444', 'email' => 'salma@dhaka-model.test']);

        app(AdmissionService::class)->submit([
            'applied_class_id' => $class6->id, 'applicant_name' => 'Imran Kabir', 'applicant_name_bn' => 'ইমরান কবির',
            'date_of_birth' => '2014-09-02', 'gender' => 'male', 'guardian_name' => 'Humayun Kabir', 'guardian_relationship' => 'father',
            'guardian_phone' => '01755555555', 'address' => 'Uttara, Dhaka',
        ]);
    }
}
