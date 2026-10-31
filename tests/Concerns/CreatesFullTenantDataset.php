<?php

namespace Tests\Concerns;

use App\Models\School;
use App\Models\User;
use App\Modules\Admissions\Services\AdmissionService;
use App\Modules\Attendance\Models\StudentAttendance;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Exams\Models\ExamMarkSheet;
use App\Modules\Exams\Models\ExamSubject;
use App\Modules\Exams\Models\Examination;
use App\Modules\Exams\Models\ExamType;
use App\Modules\Exams\Services\GradingScaleService;
use App\Modules\Exports\Models\DataExport;
use App\Modules\Finance\Models\FeeCategory;
use App\Modules\Finance\Models\FeeDiscount;
use App\Modules\Finance\Models\FeeInvoice;
use App\Modules\Finance\Models\FeeStructure;
use App\Modules\Finance\Models\StudentFeeAssignment;
use App\Modules\Finance\Services\BillingService;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Homework\Models\Homework;
use App\Modules\Imports\Models\DataImportJob;
use App\Modules\Messaging\Models\NotificationLog;
use App\Modules\Notices\Models\Event;
use App\Modules\Notices\Models\Notice;
use App\Modules\Setup\Models\Campus;
use App\Modules\Support\Models\SupportAccessGrant;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Teaching\Models\ClassRoutine;
use App\Modules\Teaching\Models\Holiday;
use App\Modules\Website\Models\GalleryAlbum;
use App\Modules\Website\Models\GalleryPhoto;
use App\Modules\Website\Models\WebsiteMenuItem;
use App\Modules\Website\Models\WebsitePage;
use App\Support\Tenancy\TenantStorage;
use Carbon\CarbonImmutable;

/**
 * One record of every kind inside a school, for cross-tenant sweeps.
 * Returns route-parameter name => id (or value) of the records.
 */
trait CreatesFullTenantDataset
{
    use CreatesAcademicStructure, CreatesTeachers;

    /** @return array<string, int|string> */
    protected function fullDataset(School $school): array
    {
        return $this->inSchool($school, function () use ($school) {
            $owner = $this->ownerOf($school);
            $s = $this->academicStructure();
            $storage = app(TenantStorage::class);
            $file = $storage->path('private/sample.pdf');
            $storage->disk()->put($file, '%PDF sample');
            $media = $storage->path('website/site/sample-'.$school->slug.'.jpg');
            $storage->disk()->put($media, 'jpg');

            $campus = Campus::create(['name' => 'Campus '.$school->slug]);
            $subject = $this->subjectFor($s['session'], $s['class6'], 'Math');
            [, $staff] = $this->teacher('Teacher', 'Teacher '.$school->slug);
            $assignment = $this->assign($staff, $s['session'], $s['class6'], $s['sectionA']->id, $subject);
            $routine = ClassRoutine::create(['academic_session_id' => $s['session']->id, 'class_id' => $s['class6']->id, 'section_id' => $s['sectionA']->id,
                'subject_id' => $subject->id, 'staff_member_id' => $staff->id, 'day_of_week' => 1, 'starts_at' => '09:00', 'ends_at' => '09:40']);
            $holiday = Holiday::create(['name' => 'Holiday', 'start_date' => '2026-12-16', 'end_date' => '2026-12-16']);
            $student = $this->makeStudent($s['session'], $s['class6'], $s['sectionA'], ['full_name' => 'Secret '.$school->slug], '017'.str_pad((string) $school->id, 8, '0', STR_PAD_LEFT));
            $guardian = $student->guardians()->firstOrFail();

            app(AttendanceService::class)->record($s['sectionA'], CarbonImmutable::today(), [$student->id => ['status' => 'absent']]);
            $mark = StudentAttendance::query()->firstOrFail();

            $homework = Homework::create(['academic_session_id' => $s['session']->id, 'class_id' => $s['class6']->id, 'section_id' => $s['sectionA']->id,
                'subject_id' => $subject->id, 'title' => 'HW', 'assigned_on' => '2026-03-01', 'status' => 'published']);
            $homework->forceFill(['attachment_path' => $file, 'attachment_name' => 'hw.pdf'])->save();

            $admission = app(AdmissionService::class)->submit(['applied_class_id' => $s['class6']->id, 'applicant_name' => 'Applicant', 'date_of_birth' => '2015-01-01',
                'gender' => 'male', 'guardian_name' => 'G', 'guardian_relationship' => 'father', 'guardian_phone' => '01799999999', 'address' => 'Dhaka']);
            $import = new DataImportJob(['import_type' => 'students', 'original_name' => 'x.csv', 'options' => []]);
            $import->forceFill(['user_id' => $owner->id, 'source_path' => $file, 'status' => 'validated'])->save();

            $type = ExamType::create(['name' => 'Half-Yearly']);
            $scale = app(GradingScaleService::class)->fromPreset('gpa5');
            $exam = Examination::create(['academic_session_id' => $s['session']->id, 'exam_type_id' => $type->id, 'grading_scale_id' => $scale->id, 'name' => 'Exam', 'starts_on' => '2026-06-01', 'ends_on' => '2026-06-10']);
            $examSubject = ExamSubject::create(['examination_id' => $exam->id, 'class_id' => $s['class6']->id, 'subject_id' => $subject->id, 'subject_type' => 'compulsory', 'pass_marks' => 33]);
            $sheet = new ExamMarkSheet(['exam_subject_id' => $examSubject->id, 'section_id' => $s['sectionA']->id]);
            $sheet->forceFill(['class_id' => $s['class6']->id]);
            $sheet->save();

            $category = FeeCategory::create(['name' => 'Tuition']);
            $structure = FeeStructure::create(['academic_session_id' => $s['session']->id, 'class_id' => $s['class6']->id, 'fee_category_id' => $category->id,
                'name' => 'Tuition', 'amount' => '1000.00', 'frequency' => 'monthly', 'start_period' => '2026-01', 'applies_to' => 'class']);
            $feeAssignment = StudentFeeAssignment::create(['student_id' => $student->id, 'fee_structure_id' => $structure->id]);
            $discount = FeeDiscount::create(['student_id' => $student->id, 'academic_session_id' => $s['session']->id, 'name' => 'Waiver', 'kind' => 'percent', 'value' => '10.00', 'created_by' => $owner->id]);
            app(BillingService::class)->generate('2026-03', null, CarbonImmutable::parse('2026-03-10'), $owner);
            $invoice = FeeInvoice::query()->firstOrFail();
            $payment = app(PaymentService::class)->record($student, ['amount' => '100.00', 'method' => 'cash', 'idempotency_key' => 'k-'.$school->slug], null, $owner);

            $page = WebsitePage::create(['slug' => 'private-'.$school->slug, 'title' => 'Page', 'status' => 'published']);
            $item = WebsiteMenuItem::create(['location' => 'header', 'label' => 'Page', 'link_type' => 'page', 'website_page_id' => $page->id]);
            $album = GalleryAlbum::create(['title' => 'Album', 'is_public' => true]);
            $photo = GalleryPhoto::create(['gallery_album_id' => $album->id, 'path' => str_replace($storage->root().'/', '', $media), 'thumb_path' => str_replace($storage->root().'/', '', $media), 'width' => 10, 'height' => 10]);
            $notice = Notice::create(['title' => 'Notice '.$school->slug, 'body' => 'b', 'audiences' => ['public', 'parents', 'students', 'staff'], 'status' => 'published']);
            $notice->forceFill(['attachment_path' => $file, 'attachment_name' => 'n.pdf'])->save();
            $event = Event::create(['title' => 'Event', 'starts_on' => '2026-12-01', 'is_public' => true]);

            $export = DataExport::create(['requested_by' => $owner->id]);
            $export->forceFill(['status' => 'ready', 'path' => $file, 'expires_at' => now()->addDay()])->save();
            $log = NotificationLog::create(['channel' => 'mail', 'recipient' => 'g@example.com', 'body' => 'b', 'status' => 'failed']);

            $ticket = SupportTicket::create(['school_id' => $school->id, 'reference' => 'SUP-'.$school->slug, 'subject' => 'S', 'category' => 'technical', 'priority' => 'normal']);
            $agent = \App\Models\PlatformUser::factory()->create();
            $grant = SupportAccessGrant::create(['school_id' => $school->id, 'platform_user_id' => $agent->id, 'reason' => 'Looking into something', 'duration_hours' => 2]);

            return [
                'campus' => $campus->id, 'session' => $s['session']->id, 'class' => $s['class6']->id, 'section' => $s['sectionA']->id,
                'subject' => $subject->id, 'exam_subject' => $examSubject->id, 'student' => $student->id, 'guardian' => $guardian->id, 'staff' => $staff->id,
                'admission' => $admission->id, 'import' => $import->id, 'assignment' => $assignment->id, 'fee_assignment' => $feeAssignment->id,
                'routine' => $routine->id, 'holiday' => $holiday->id, 'mark' => $mark->id, 'homework' => $homework->id, 'type' => $type->id, 'scale' => $scale->id,
                'exam' => $exam->id, 'sheet' => $sheet->id, 'category' => $category->id, 'structure' => $structure->id, 'discount' => $discount->id,
                'invoice' => $invoice->id, 'payment' => $payment->id, 'page' => $page->id, 'slug' => $page->slug, 'item' => $item->id, 'album' => $album->id,
                'photo' => $photo->id, 'path' => $photo->path, 'notice' => $notice->id, 'event' => $event->id, 'export' => $export->id, 'log' => $log->id,
                'ticket' => $ticket->id, 'grant' => $grant->id, 'owner' => $owner->id,
            ];
        });
    }
}
