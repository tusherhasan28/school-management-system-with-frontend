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
        $this->seedBilling($dhaka, $tenant);

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
        $this->seedExams($session, $class6);
        $this->seedFees($session, $class6);
        $this->seedWebsite($salma);
        $this->seedPortals();

        app(AdmissionService::class)->submit([
            'applied_class_id' => $class6->id, 'applicant_name' => 'Imran Kabir', 'applicant_name_bn' => 'ইমরান কবির',
            'date_of_birth' => '2014-09-02', 'gender' => 'male', 'guardian_name' => 'Humayun Kabir', 'guardian_relationship' => 'father',
            'guardian_phone' => '01755555555', 'address' => 'Uttara, Dhaka',
        ]);
    }

    private function seedOperations(\App\Modules\Academics\Models\AcademicSession $session, SchoolClass $class6, \App\Modules\Academics\Models\Section $sectionA, \App\Modules\People\Models\StaffMember $salma): void
    {
        $subjects = \App\Modules\Academics\Models\Subject::query()->pluck('id', 'code');

        $order = array_flip(['BAN', 'ENG', 'MATH', 'SCI', 'BGS', 'REL', 'ICT']);

        foreach ($subjects as $code => $id) {
            \App\Modules\Academics\Models\ClassSubject::create(['academic_session_id' => $session->id, 'class_id' => $class6->id, 'subject_id' => $id, 'full_marks' => 100, 'pass_marks' => 33, 'sort_order' => ($order[$code] ?? 9) + 1]);
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

    /**
     * Exam types, a grading scale from the GPA 5 preset, a published Half-Yearly
     * exam for Class 6 (marks entered, verified, calculated, approved, published)
     * and a draft Annual exam.
     */
    private function seedExams(\App\Modules\Academics\Models\AcademicSession $session, SchoolClass $class6): void
    {
        $principal = \App\Models\User::query()->where('email', 'principal@dhaka-model.test')->firstOrFail();

        foreach ([['Class test', 'শ্রেণি পরীক্ষা'], ['Half-Yearly', 'অর্ধবার্ষিক'], ['Annual', 'বার্ষিক']] as $i => [$name, $bn]) {
            \App\Modules\Exams\Models\ExamType::create(['name' => $name, 'name_bn' => $bn, 'sort_order' => $i]);
        }

        $scale = app(\App\Modules\Exams\Services\GradingScaleService::class)->fromPreset('gpa5');
        $setup = app(\App\Modules\Exams\Services\ExamSetupService::class);
        $marks = app(\App\Modules\Exams\Services\MarkEntryService::class);
        $results = app(\App\Modules\Exams\Services\ResultService::class);
        $type = fn (string $name) => \App\Modules\Exams\Models\ExamType::query()->where('name', $name)->value('id');

        $exam = $setup->create([
            'academic_session_id' => $session->id, 'exam_type_id' => $type('Half-Yearly'), 'grading_scale_id' => $scale->id,
            'name' => 'Half-Yearly Examination', 'name_bn' => 'অর্ধবার্ষিক পরীক্ষা', 'starts_on' => '2026-06-14', 'ends_on' => '2026-06-25',
        ]);
        $setup->addClassSubjects($exam, $class6->id, null, 'written_mcq');

        foreach ($exam->subjects()->orderBy('sort_order')->get() as $i => $subject) {
            $subject->update(['exam_date' => \Carbon\CarbonImmutable::parse('2026-06-14')->addDays($i + intdiv($i, 5))->toDateString(), 'starts_at' => '10:00', 'duration_minutes' => 180]);
        }

        $setup->openMarksEntry($exam);

        // Written (out of 70) and MCQ (out of 30) per student, varied by subject.
        $base = [[62, 27], [50, 22], [44, 19], [36, 15]];

        foreach (\App\Modules\Exams\Models\ExamMarkSheet::query()->with('examSubject.components', 'examSubject.subject')->get() as $n => $sheet) {
            [$written, $mcq] = $sheet->examSubject->components->pluck('id')->all();
            $rows = [];

            foreach ($marks->roster($exam, $sheet->class_id, $sheet->section_id) as $s => $enrollment) {
                [$w, $m] = $base[$s % 4];
                $w = min(70, max(0, $w + (($n * 7 + $s * 3) % 11) - 4));
                $m = min(30, max(0, $m + (($n * 5 + $s) % 7) - 3));

                if ($s === 3 && $sheet->examSubject->subject?->code === 'SCI') {
                    [$w, $m] = [18, 6]; // one failed subject for the demo
                }

                $rows[$enrollment->student_id] = ['status' => 'present', 'parts' => [$written => (string) $w, $mcq => (string) $m]];
            }

            if ($rows !== []) {
                $marks->save($sheet, $rows, $principal);
            }

            $marks->submit($sheet->refresh(), $principal);
            $marks->verify($sheet->refresh(), $principal);
        }

        $results->calculate($exam, $principal);
        $results->approve($exam->refresh()->load('currentCalculation'), $principal);
        $results->publish($exam->refresh()->load('currentCalculation'), $principal, false);

        $annual = $setup->create([
            'academic_session_id' => $session->id, 'exam_type_id' => $type('Annual'), 'grading_scale_id' => $scale->id,
            'name' => 'Annual Examination', 'name_bn' => 'বার্ষিক পরীক্ষা', 'starts_on' => '2026-11-22', 'ends_on' => '2026-12-03',
        ]);
        $setup->addClassSubjects($annual, $class6->id, null, 'written_mcq');
    }

    /**
     * Fee heads and plans, an accountant login, invoices for September to
     * November for Class 6, and a mix of payments: paid in full, partly paid,
     * a sibling discount, an opt-in transport fee and one family overdue.
     */
    private function seedFees(\App\Modules\Academics\Models\AcademicSession $session, SchoolClass $class6): void
    {
        $accountant = \App\Models\User::create(['name' => 'Farhana Akter', 'email' => 'accounts@dhaka-model.test', 'password' => 'password']);
        $accountant->forceFill(['status' => 'active', 'email_verified_at' => now()])->save();
        $accountant->assignRole('Accountant');

        $cat = fn (string $name, string $bn, int $order) => \App\Modules\Finance\Models\FeeCategory::create(['name' => $name, 'name_bn' => $bn, 'sort_order' => $order]);
        $tuition = $cat('Tuition', 'বেতন', 1);
        $exam = $cat('Exam fee', 'পরীক্ষা ফি', 2);
        $transport = $cat('Transport', 'পরিবহন', 3);
        $cat('Admission', 'ভর্তি ফি', 4);

        $plan = fn (array $a) => \App\Modules\Finance\Models\FeeStructure::create($a + ['academic_session_id' => $session->id, 'applies_to' => 'class']);
        $plan(['class_id' => $class6->id, 'fee_category_id' => $tuition->id, 'name' => 'Monthly tuition', 'amount' => '1200.00', 'frequency' => 'monthly', 'start_period' => '2026-01']);
        $plan(['class_id' => null, 'fee_category_id' => $exam->id, 'name' => 'Exam fee', 'amount' => '500.00', 'frequency' => 'half_yearly', 'start_period' => '2026-06']);
        $bus = $plan(['class_id' => null, 'fee_category_id' => $transport->id, 'name' => 'School bus', 'amount' => '800.00', 'frequency' => 'monthly', 'start_period' => '2026-01', 'applies_to' => 'assigned']);

        $students = \App\Modules\People\Models\Student::query()->orderBy('id')->get()->keyBy('full_name');
        \App\Modules\Finance\Models\StudentFeeAssignment::create(['student_id' => $students['Tanvir Hasan']->id, 'fee_structure_id' => $bus->id, 'note' => 'Uttara route']);
        \App\Modules\Finance\Models\FeeDiscount::create([
            'student_id' => $students['Nusrat Jahan']->id, 'academic_session_id' => $session->id, 'fee_category_id' => $tuition->id,
            'name' => 'Sibling discount', 'kind' => 'percent', 'value' => '10.00', 'created_by' => $accountant->id,
        ]);

        app(\App\Modules\Finance\Services\FinanceSettings::class)->save([
            'due_day' => 10, 'late_fee_enabled' => true, 'late_fee_kind' => 'fixed', 'late_fee_value' => '50.00', 'late_fee_grace_days' => 7,
            'receipt_note' => 'Fees once paid are not refundable except as decided by the school authority.',
        ]);

        $billing = app(\App\Modules\Finance\Services\BillingService::class);

        foreach (['2026-09', '2026-10', '2026-11'] as $period) {
            $billing->generate($period, $class6->id, \Carbon\CarbonImmutable::parse($period.'-10'), $accountant);
        }

        $payments = app(\App\Modules\Finance\Services\PaymentService::class);
        $pay = fn (string $name, string $amount, string $method, string $on, ?string $ref = null) => $payments->record(
            $students[$name],
            ['amount' => $amount, 'method' => $method, 'reference' => $ref, 'received_at' => \Carbon\CarbonImmutable::parse($on.' 11:30', 'Asia/Dhaka')->utc(), 'idempotency_key' => 'seed-'.md5($name.$amount.$on)],
            null,
            $accountant,
        );

        $pay('Ayesha Rahman', '1200.00', 'cash', '2026-09-05');
        $pay('Ayesha Rahman', '1200.00', 'mobile_banking', '2026-10-04', 'BKS8XK2M1P');
        $pay('Tanvir Hasan', '2000.00', 'cash', '2026-09-09');
        $pay('Tanvir Hasan', '1500.00', 'bank', '2026-10-12', 'DBBL-REF-77120');
        $pay('Nusrat Jahan', '2160.00', 'cash', '2026-10-08');

        // Rafiul has paid nothing: September and October are overdue and get the late fee.
        $billing->applyLateFees(\Carbon\CarbonImmutable::now('Asia/Dhaka')->startOfDay());
    }

    /** Milestone 6: a published website with notices, events and a small gallery. */
    private function seedWebsite(\App\Modules\People\Models\StaffMember $salma): void
    {
        $settings = \App\Modules\Website\Models\WebsiteSetting::current();
        $settings->fill([
            'template' => 'classic', 'theme_color' => '#0f766e', 'default_language' => 'bn', 'is_published' => true, 'show_teachers' => true, 'eiin' => '108765',
            'tagline' => 'Learning with care since 1998', 'tagline_bn' => '১৯৯৮ সাল থেকে যত্নের সাথে শিক্ষা',
            'hero_title' => 'Admission for 2027 is open', 'hero_title_bn' => '২০২৭ শিক্ষাবর্ষে ভর্তি চলছে',
            'hero_text' => 'Classes 1 to 10, Bangla and English versions.', 'hero_text_bn' => 'প্রথম থেকে দশম শ্রেণি, বাংলা ও ইংরেজি ভার্সন।',
            'about' => "Dhaka Model School is a co-educational school in Mirpur, Dhaka.\n\n- Spacious classrooms\n- Science and ICT labs\n- Library and playground",
            'about_bn' => "ঢাকা মডেল স্কুল মিরপুর, ঢাকার একটি সহশিক্ষা প্রতিষ্ঠান।\n\n- প্রশস্ত শ্রেণিকক্ষ\n- বিজ্ঞান ও আইসিটি ল্যাব\n- লাইব্রেরি ও খেলার মাঠ",
            'principal_name' => 'Rahim Uddin', 'principal_name_bn' => 'রহিম উদ্দিন',
            'principal_message' => 'We help every child grow in knowledge and character.', 'principal_message_bn' => 'আমরা প্রতিটি শিশুকে জ্ঞানে ও চরিত্রে বড় হতে সাহায্য করি।',
            'office_hours' => 'Sunday–Thursday, 8:00 am – 3:00 pm', 'office_hours_bn' => 'রবিবার–বৃহস্পতিবার, সকাল ৮টা – বিকাল ৩টা',
            'seo_description' => 'Dhaka Model School, Mirpur: classes 1–10, admission, notices and results.',
        ])->save();
        $salma->forceFill(['show_on_website' => true])->save();

        \App\Modules\Website\Models\WebsitePage::create([
            'slug' => 'uniform', 'title' => 'School uniform', 'title_bn' => 'স্কুল পোশাক', 'status' => 'published',
            'body' => "## Boys\nWhite shirt, navy trousers.\n\n## Girls\nWhite kameez, navy salwar and orna.",
            'body_bn' => "## ছেলে\nসাদা শার্ট, নেভি প্যান্ট।\n\n## মেয়ে\nসাদা কামিজ, নেভি সালোয়ার ও ওড়না।",
        ])->forceFill(['published_at' => now()])->save();

        $notice = fn (array $a) => \App\Modules\Notices\Models\Notice::create($a + ['status' => 'published']);
        $notice(['title' => 'Annual exam routine published', 'title_bn' => 'বার্ষিক পরীক্ষার রুটিন প্রকাশিত', 'body' => 'The annual exam starts on **1 December**.', 'body_bn' => 'বার্ষিক পরীক্ষা **১ ডিসেম্বর** শুরু হবে।', 'audiences' => ['public'], 'is_pinned' => true]);
        $notice(['title' => 'Parents meeting on Saturday', 'title_bn' => 'শনিবার অভিভাবক সভা', 'body' => 'Class 6 parents: 10 am in the hall.', 'body_bn' => '৬ষ্ঠ শ্রেণির অভিভাবকগণ: সকাল ১০টা, হলরুমে।', 'audiences' => ['parents']]);
        $notice(['title' => 'Bring your science copies', 'title_bn' => 'বিজ্ঞান খাতা নিয়ে আসবে', 'body' => 'Class 6: practical copies on Monday.', 'audiences' => ['students']]);
        $notice(['title' => 'Staff meeting', 'title_bn' => 'শিক্ষক সভা', 'body' => 'Thursday after the last period.', 'audiences' => ['staff']]);

        \App\Modules\Notices\Models\Event::create(['title' => 'Victory Day programme', 'title_bn' => 'বিজয় দিবস অনুষ্ঠান', 'starts_on' => '2026-12-16', 'location' => 'School field', 'is_public' => true]);
        \App\Modules\Notices\Models\Event::create(['title' => 'Annual sports day', 'title_bn' => 'বার্ষিক ক্রীড়া প্রতিযোগিতা', 'starts_on' => '2027-01-20', 'ends_on' => '2027-01-21', 'location' => 'School field', 'is_public' => true]);

        // Generated placeholder photos (no real people), stored through the same re-encoding path as uploads.
        $album = \App\Modules\Website\Models\GalleryAlbum::create(['title' => 'Science fair 2026', 'title_bn' => 'বিজ্ঞান মেলা ২০২৬', 'taken_on' => '2026-08-20', 'is_public' => true]);
        $media = app(\App\Modules\Website\Services\WebsiteMedia::class);

        foreach ([[15, 118, 110], [234, 179, 8], [37, 99, 235]] as $i => [$r, $g, $b]) {
            $img = imagecreatetruecolor(1200, 800);
            imagefill($img, 0, 0, imagecolorallocate($img, $r, $g, $b));
            imagefilledellipse($img, 600, 400, 420, 420, imagecolorallocatealpha($img, 255, 255, 255, 80));
            $tmp = tempnam(sys_get_temp_dir(), 'seed').'.jpg';
            imagejpeg($img, $tmp, 85);
            $file = new \Illuminate\Http\UploadedFile($tmp, 'photo-'.($i + 1).'.jpg', 'image/jpeg', null, true);
            \App\Modules\Website\Models\GalleryPhoto::create(['gallery_album_id' => $album->id, 'sort_order' => $i + 1, 'caption' => 'Project '.($i + 1)] + $media->storeImage($file, 'gallery/'.$album->id));
        }
    }

    /** Milestone 6: demo family logins (activated directly; real ones come by invitation). */
    private function seedPortals(): void
    {
        $accounts = app(\App\Modules\Portal\Services\PortalAccounts::class);
        $students = \App\Modules\People\Models\Student::query()->get()->keyBy('full_name');

        $guardian = $students['Ayesha Rahman']->guardians()->firstOrFail(); // also Nusrat's father
        $guardian->update(['email' => 'parent@dhaka-model.test']);
        $accounts->inviteGuardian($guardian->fresh())->forceFill(['password' => 'password', 'status' => 'active', 'email_verified_at' => now()])->save();

        $students['Tanvir Hasan']->update(['email' => 'student@dhaka-model.test']);
        $accounts->inviteStudent($students['Tanvir Hasan']->fresh())->forceFill(['password' => 'password', 'status' => 'active', 'email_verified_at' => now()])->save();
    }

    /** Milestone 7: plans, subscriptions, invoices, a support ticket, messaging. */
    private function seedBilling(School $dhaka, TenantContext $tenant): void
    {
        config(['queue.default' => 'sync']); // demo messages are delivered at once (log provider)

        foreach (['billing_support' => 'Billing Desk', 'support_agent' => 'Support Agent'] as $role => $name) {
            $user = new PlatformUser(['name' => $name, 'email' => str_replace('_', '-', $role).'@'.config('tenancy.domain'), 'password' => 'password']);
            $user->role = PlatformRole::from($role);
            $user->email_verified_at = now();
            $user->save();
        }

        $plan = function (string $code, string $name, string $bn, string $monthly, string $yearly, array $features, int $order) {
            $p = \App\Modules\Billing\Models\Plan::create(['code' => $code, 'name' => $name, 'name_bn' => $bn, 'price_monthly' => $monthly, 'price_yearly' => $yearly,
                'trial_days' => 30, 'grace_days' => 10, 'sort_order' => $order, 'description' => 'Demo plan — prices are placeholders.']);
            foreach ($features as $key => $value) {
                \App\Modules\Billing\Models\PlanFeature::create(['plan_id' => $p->id, 'feature_key' => $key, 'feature_value' => $value]);
            }

            return $p;
        };
        $starter = $plan('starter', 'Starter', 'স্টার্টার', '1500.00', '15000.00', ['students' => 300, 'staff' => 30, 'sms_per_month' => 500, 'storage_mb' => 1024,
            'website' => true, 'parent_portal' => true, 'student_portal' => false, 'online_payments' => false, 'sms' => true, 'bulk_import' => true], 1);
        $standard = $plan('standard', 'Standard', 'স্ট্যান্ডার্ড', '3500.00', '35000.00', ['students' => 1500, 'staff' => 120, 'sms_per_month' => 3000, 'storage_mb' => 10240,
            'website' => true, 'parent_portal' => true, 'student_portal' => true, 'online_payments' => true, 'sms' => true, 'bulk_import' => true], 2);
        $plan('campus', 'Campus', 'ক্যাম্পাস', '9000.00', '90000.00', ['students' => null, 'staff' => null, 'sms_per_month' => 15000, 'storage_mb' => 51200,
            'website' => true, 'parent_portal' => true, 'student_portal' => true, 'online_payments' => true, 'sms' => true, 'bulk_import' => true], 3);

        $billing = app(\App\Modules\Billing\Services\SubscriptionService::class);
        $sub = $billing->assign($dhaka, $standard, 'monthly', start: \Carbon\CarbonImmutable::parse('2026-10-15 00:00', 'Asia/Dhaka')->utc());
        $first = $sub->invoices()->sole();
        $billing->recordPayment($first, ['amount' => $first->total, 'method' => 'bank', 'transaction_id' => 'DEMO-DBBL-7781', 'received_at' => \Carbon\CarbonImmutable::parse('2026-10-16 11:00', 'Asia/Dhaka')->utc()]);
        $billing->tick(); // nothing due yet: the renewal invoice comes 7 days before 15 November

        $ctg = School::query()->where('slug', 'ctg-madrasa')->firstOrFail();
        $billing->assign($ctg, $starter, 'yearly', trial: true);

        $tenant->run($dhaka, function (School $school) {
            $owner = \App\Models\User::query()->where('email', 'principal@dhaka-model.test')->firstOrFail();
            $support = app(\App\Modules\Support\Services\SupportService::class);
            $ticket = $support->open($school, $owner, ['subject' => 'How do we add a second campus?', 'category' => 'technical', 'priority' => 'normal',
                'message' => 'We are opening a branch in Uttara next year. Can both campuses use one account?']);
            $agent = PlatformUser::query()->where('role', 'support_agent')->firstOrFail();
            $support->replyFromPlatform($ticket, $agent, "Yes. Add it under Campuses; classes and staff can be assigned per campus. We can walk you through it on a call.", false);

            \App\Modules\Messaging\Models\SmsProviderAccount::create(['provider' => 'log', 'sender_id' => 'DHAKAMODEL', 'is_enabled' => true]);
            $settings = app(\App\Modules\Setup\Services\SchoolSettings::class);
            $settings->put('messaging.channels.attendance.absent', ['sms', 'mail']);
            $settings->put('messaging.channels.results.published', ['sms', 'mail']);

            $messages = app(\App\Modules\Messaging\Services\MessageService::class);
            $recipients = \App\Modules\People\Models\Guardian::query()->get()->map(fn ($g) => ['name' => $g->full_name, 'phone' => $g->phone, 'email' => $g->email,
                'data' => ['message' => 'আগামীকাল বিজয় দিবসের অনুষ্ঠান সকাল ৯টায় শুরু হবে।']])->all();
            $messages->send('general', $recipients, ['sms', 'mail'], $owner);
        });
    }
}
