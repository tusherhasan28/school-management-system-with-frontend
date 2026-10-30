<?php

namespace Tests\Feature\Admissions;

use App\Modules\Admissions\Models\AdmissionApplication;
use App\Modules\People\Models\Guardian;
use App\Modules\People\Models\Student;
use App\Modules\People\Models\StudentEnrollment;
use Tests\Concerns\CreatesAcademicStructure;
use Tests\TenantTestCase;

class AdmissionFlowTest extends TenantTestCase
{
    use CreatesAcademicStructure;

    /** @var array<string, mixed> */
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = $this->academicStructure();
    }

    private function form(array $overrides = []): array
    {
        return array_merge([
            'applied_class_id' => $this->s['class6']->id,
            'applicant_name' => 'Imran Kabir',
            'applicant_name_bn' => 'ইমরান কবির',
            'date_of_birth' => '2014-09-02',
            'gender' => 'male',
            'guardian_name' => 'Humayun Kabir',
            'guardian_relationship' => 'father',
            'guardian_phone' => '01755555555',
            'address' => 'Uttara, Dhaka',
            'father_name' => 'Humayun Kabir',
            'religion' => 'Islam',
        ], $overrides);
    }

    private function submit(array $overrides = []): AdmissionApplication
    {
        $this->post(route('public.admission.store'), $this->form($overrides))->assertSessionHasNoErrors();

        return AdmissionApplication::query()->latest('id')->firstOrFail();
    }

    public function test_public_form_is_available_without_login_and_shows_only_class_names(): void
    {
        $this->makeStudent($this->s['session'], $this->s['class6'], overrides: ['full_name' => 'Private Student']);

        $this->get(route('public.admission'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('tenant/public/admission')
                ->has('classes', 2)
                ->where('session', '2026')
                ->missing('students'))
            ->assertDontSee('Private Student');
    }

    public function test_application_is_submitted_with_a_number_in_the_current_session(): void
    {
        $this->post(route('public.admission.store'), $this->form())
            ->assertRedirect(route('public.admission'))
            ->assertSessionHas('admission_no');

        $app = AdmissionApplication::query()->sole();
        $this->assertMatchesRegularExpression('/^APP-\d{4}-00001$/', $app->application_no);
        $this->assertSame('submitted', $app->status);
        $this->assertSame($this->s['session']->id, $app->academic_session_id);
        $this->assertEquals(['father_name' => 'Humayun Kabir', 'religion' => 'Islam'], $app->application_data);
    }

    public function test_honeypot_and_foreign_class_are_rejected(): void
    {
        $this->post(route('public.admission.store'), $this->form(['website' => 'http://spam.test']))->assertSessionHasErrors('website');

        $beta = $this->provisionSchool('beta');
        $betaClass = $this->inSchool($beta, fn () => $this->academicStructure()['class6']);
        $this->onSchool($this->school);

        $this->post(route('public.admission.store'), $this->form(['applied_class_id' => $betaClass->id]))->assertSessionHasErrors('applied_class_id');
        $this->assertSame(0, AdmissionApplication::query()->count());
    }

    public function test_public_form_is_rate_limited(): void
    {
        foreach (range(1, 3) as $i) {
            $this->post(route('public.admission.store'), $this->form(['applicant_name' => "Kid {$i}"]))->assertRedirect();
        }

        $this->post(route('public.admission.store'), $this->form())->assertTooManyRequests();
    }

    public function test_review_approve_and_convert_creates_student_guardian_and_enrollment(): void
    {
        $app = $this->submit();
        $owner = $this->ownerOf($this->school);

        $this->actingAs($owner)->post(route('admissions.review', $app), ['status' => 'under_review'])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('admissions.review', $app), ['status' => 'approved'])->assertSessionHasNoErrors();

        $this->actingAs($owner)
            ->post(route('admissions.convert', $app), ['class_id' => $this->s['class6']->id, 'section_id' => $this->s['sectionA']->id, 'roll_no' => '12'])
            ->assertRedirect();

        $student = Student::query()->sole();
        $this->assertSame('Imran Kabir', $student->full_name);
        $this->assertSame('ইমরান কবির', $student->name_bn);
        $this->assertSame('Islam', $student->religion);
        $this->assertSame('01755555555', $student->guardians()->sole()->phone);
        $this->assertDatabaseHas('student_enrollments', ['student_id' => $student->id, 'section_id' => $this->s['sectionA']->id, 'roll_no' => '12', 'academic_session_id' => $this->s['session']->id]);

        $app->refresh();
        $this->assertSame('enrolled', $app->status);
        $this->assertSame($student->id, $app->student_id);
    }

    public function test_conversion_reuses_an_existing_guardian_for_siblings(): void
    {
        $this->makeStudent($this->s['session'], $this->s['class7'], overrides: ['guardian_name' => 'Humayun Kabir'], guardianPhone: '01755555555');
        $app = $this->submit();
        $owner = $this->ownerOf($this->school);
        $this->actingAs($owner)->post(route('admissions.review', $app), ['status' => 'approved']);
        $this->actingAs($owner)->post(route('admissions.convert', $app), ['class_id' => $this->s['class6']->id]);

        $this->assertSame(1, Guardian::query()->count());
        $this->assertSame(2, Guardian::query()->sole()->students()->count());
    }

    public function test_conversion_requires_approval_and_happens_only_once(): void
    {
        $app = $this->submit();
        $owner = $this->ownerOf($this->school);

        $this->actingAs($owner)->post(route('admissions.convert', $app), ['class_id' => $this->s['class6']->id])->assertSessionHasErrors('status');
        $this->assertSame(0, Student::query()->count());

        $this->actingAs($owner)->post(route('admissions.review', $app), ['status' => 'approved']);
        $this->actingAs($owner)->post(route('admissions.convert', $app), ['class_id' => $this->s['class6']->id]);
        $this->actingAs($owner)->post(route('admissions.convert', $app), ['class_id' => $this->s['class6']->id])->assertSessionHasErrors('status');

        $this->assertSame(1, Student::query()->count());
        $this->assertSame(1, StudentEnrollment::query()->count());
    }

    public function test_rejection_needs_a_reason_and_invalid_transitions_are_refused(): void
    {
        $app = $this->submit();
        $owner = $this->ownerOf($this->school);

        $this->actingAs($owner)->post(route('admissions.review', $app), ['status' => 'rejected'])->assertSessionHasErrors('note');
        $this->actingAs($owner)->post(route('admissions.review', $app), ['status' => 'rejected', 'note' => 'Seats full'])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('admissions.review', $app), ['status' => 'approved'])->assertSessionHasErrors('status');
        $this->assertSame('rejected', $app->fresh()->status);
    }

    public function test_applications_of_another_school_are_not_reachable(): void
    {
        $beta = $this->provisionSchool('beta');
        $betaApp = $this->inSchool($beta, function () {
            $s = $this->academicStructure();

            return app(\App\Modules\Admissions\Services\AdmissionService::class)->submit($this->form(['applied_class_id' => $s['class6']->id]));
        });
        $this->onSchool($this->school);
        $owner = $this->ownerOf($this->school);

        $this->actingAs($owner)->get(route('admissions.show', $betaApp->id))->assertNotFound();
        $this->actingAs($owner)->post(route('admissions.review', $betaApp->id), ['status' => 'approved'])->assertNotFound();
        $this->actingAs($owner)->post(route('admissions.convert', $betaApp->id), ['class_id' => $this->s['class6']->id])->assertNotFound();
        $this->assertSame('submitted', $this->inSchool($beta, fn () => AdmissionApplication::query()->find($betaApp->id)->status));
    }

    public function test_receptionist_can_review_but_not_convert(): void
    {
        $app = $this->submit();
        $receptionist = $this->userWithRole('Receptionist');

        $this->actingAs($receptionist)->post(route('admissions.review', $app), ['status' => 'approved'])->assertSessionHasNoErrors();
        $this->actingAs($receptionist)->post(route('admissions.convert', $app), ['class_id' => $this->s['class6']->id])->assertForbidden();
    }
}
