<?php

namespace Tests\Feature\People;

use App\Models\School;
use App\Modules\People\Models\Guardian;
use App\Modules\People\Models\Student;
use App\Modules\People\Models\StudentEnrollment;
use App\Modules\People\Models\StudentGuardian;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesAcademicStructure;
use Tests\TenantTestCase;

class StudentManagementTest extends TenantTestCase
{
    use CreatesAcademicStructure;

    /** @var array<string, mixed> */
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = $this->academicStructure();
    }

    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'full_name' => 'Ayesha Rahman',
            'name_bn' => 'আয়েশা রহমান',
            'gender' => 'female',
            'date_of_birth' => '2014-02-15',
            'birth_registration_no' => '20141234567890123',
            'academic_session_id' => $this->s['session']->id,
            'class_id' => $this->s['class6']->id,
            'section_id' => $this->s['sectionA']->id,
            'roll_no' => '1',
            'guardians' => [
                ['full_name' => 'Mizanur Rahman', 'phone' => '+8801711111111', 'relationship' => 'father', 'is_primary' => true],
                ['full_name' => 'Salma Rahman', 'phone' => '01722222222', 'relationship' => 'mother'],
            ],
        ], $overrides);
    }

    public function test_admitting_a_student_creates_guardians_links_and_enrollment(): void
    {
        $this->actingAs($this->ownerOf($this->school))
            ->post(route('students.store'), $this->payload())
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $student = Student::query()->where('full_name', 'Ayesha Rahman')->firstOrFail();

        $this->assertMatchesRegularExpression('/^\d{4}0001$/', $student->admission_no);
        $this->assertSame(2, $student->guardians()->count());
        $this->assertSame('01711111111', $student->guardians()->wherePivot('is_primary', true)->first()->phone, 'Phone is normalised and the first guardian is primary.');
        $this->assertDatabaseHas('student_enrollments', [
            'school_id' => $this->school->id, 'student_id' => $student->id, 'class_id' => $this->s['class6']->id,
            'section_id' => $this->s['sectionA']->id, 'roll_no' => '1', 'status' => 'enrolled',
        ]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'student.created', 'subject_id' => $student->id]);
    }

    public function test_one_guardian_can_have_several_students_and_a_student_several_guardians(): void
    {
        $owner = $this->ownerOf($this->school);
        $this->actingAs($owner)->post(route('students.store'), $this->payload());
        $this->actingAs($owner)->post(route('students.store'), $this->payload(['full_name' => 'Nusrat Rahman', 'roll_no' => '2', 'birth_registration_no' => null]));

        $father = Guardian::query()->where('phone', '01711111111')->sole();
        $this->assertSame(2, $father->students()->count(), 'Siblings share one guardian record.');
        $this->assertSame(2, Guardian::query()->count());
        $this->assertSame(4, StudentGuardian::query()->count());
    }

    public function test_admission_numbers_are_sequential_per_school(): void
    {
        $first = $this->makeStudent($this->s['session'], $this->s['class6']);
        $second = $this->makeStudent($this->s['session'], $this->s['class6'], guardianPhone: '01733333333');

        $this->assertSame((int) $first->admission_no + 1, (int) $second->admission_no);

        // Another school starts its own sequence.
        $beta = $this->provisionSchool('beta');
        $betaStudent = $this->inSchool($beta, function () {
            $s = $this->academicStructure();

            return $this->makeStudent($s['session'], $s['class6']);
        });
        $this->assertSame($first->admission_no, $betaStudent->admission_no);
    }

    public function test_birth_registration_number_is_encrypted_at_rest_and_masked_for_viewers(): void
    {
        $this->actingAs($this->ownerOf($this->school))->post(route('students.store'), $this->payload());
        $student = Student::query()->firstOrFail();

        $raw = DB::table('students')->where('id', $student->id)->value('birth_registration_no');
        $this->assertNotSame('20141234567890123', $raw);
        $this->assertSame('20141234567890123', $student->birth_registration_no);

        $this->actingAs($this->userWithRole('Principal'))
            ->get(route('students.show', $student))
            ->assertInertia(fn ($page) => $page->where('student.birth_registration_no', '••••0123'));

        $this->actingAs($this->ownerOf($this->school))
            ->get(route('students.show', $student))
            ->assertInertia(fn ($page) => $page->where('student.birth_registration_no', '20141234567890123'));

        // A blank value on update keeps the stored number.
        $this->actingAs($this->ownerOf($this->school))
            ->put(route('students.update', $student), ['full_name' => 'Ayesha R.', 'gender' => 'female', 'birth_registration_no' => ''])
            ->assertSessionHasNoErrors();
        $this->assertSame('20141234567890123', $student->fresh()->birth_registration_no);
    }

    public function test_section_must_belong_to_the_chosen_class(): void
    {
        $this->actingAs($this->ownerOf($this->school))
            ->post(route('students.store'), $this->payload(['class_id' => $this->s['class7']->id, 'section_id' => $this->s['sectionA']->id]))
            ->assertSessionHasErrors('section_id');

        // And the database enforces it even if application checks were bypassed.
        $student = $this->makeStudent($this->s['session'], $this->s['class6']);
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('student_enrollments')->insert([
            'school_id' => $this->school->id, 'student_id' => $student->id, 'academic_session_id' => $this->s['session']->id,
            'class_id' => $this->s['class7']->id, 'section_id' => $this->s['sectionA']->id, 'status' => 'enrolled', 'enrolled_at' => now(),
        ]);
    }

    public function test_roll_numbers_are_unique_within_a_section(): void
    {
        $this->makeStudent($this->s['session'], $this->s['class6'], $this->s['sectionA'], roll: '5');

        $this->expectException(ValidationException::class);
        $this->makeStudent($this->s['session'], $this->s['class6'], $this->s['sectionA'], guardianPhone: '01744444444', roll: '5');
    }

    public function test_references_to_another_schools_class_are_rejected(): void
    {
        $beta = $this->provisionSchool('beta');
        $betaClass = $this->inSchool($beta, fn () => $this->academicStructure()['class6']);
        $this->onSchool($this->school);

        $this->actingAs($this->ownerOf($this->school))
            ->post(route('students.store'), $this->payload(['class_id' => $betaClass->id, 'section_id' => null]))
            ->assertSessionHasErrors('class_id');

        $this->assertSame(0, Student::query()->count());
    }

    public function test_students_of_another_school_cannot_be_viewed_or_changed(): void
    {
        $beta = $this->provisionSchool('beta');
        $betaStudent = $this->inSchool($beta, function () {
            $s = $this->academicStructure();

            return $this->makeStudent($s['session'], $s['class6'], overrides: ['full_name' => 'Beta Kid']);
        });
        $this->onSchool($this->school);
        $owner = $this->ownerOf($this->school);

        $this->actingAs($owner)->get(route('students.show', $betaStudent->id))->assertNotFound();
        $this->actingAs($owner)->get(route('students.edit', $betaStudent->id))->assertNotFound();
        $this->actingAs($owner)->put(route('students.update', $betaStudent->id), ['full_name' => 'X', 'gender' => 'male'])->assertNotFound();
        $this->actingAs($owner)->post(route('students.status', $betaStudent->id), ['outcome' => 'graduated'])->assertNotFound();
        $this->actingAs($owner)->get(route('students.index', ['search' => 'Beta Kid']))->assertInertia(fn ($p) => $p->has('students.data', 0));

        $this->assertSame('Beta Kid', $this->inSchool($beta, fn () => Student::query()->find($betaStudent->id)->full_name));
    }

    public function test_guardian_links_can_be_added_and_the_last_one_cannot_be_removed(): void
    {
        $student = $this->makeStudent($this->s['session'], $this->s['class6']);
        $owner = $this->ownerOf($this->school);
        $first = $student->guardians()->first();

        $this->actingAs($owner)
            ->delete(route('students.guardians.destroy', ['student' => $student, 'guardian' => $first]))
            ->assertSessionHasErrors('guardian');

        $this->actingAs($owner)
            ->post(route('students.guardians.store', $student), ['full_name' => 'Mother', 'phone' => '01799999999', 'relationship' => 'mother', 'is_primary' => true])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $student->guardians()->count());
        $this->assertSame('01799999999', $student->guardians()->wherePivot('is_primary', true)->sole()->phone);

        $this->actingAs($owner)->delete(route('students.guardians.destroy', ['student' => $student, 'guardian' => $first]))->assertSessionHasNoErrors();
        $this->assertSame(1, $student->guardians()->count());
    }

    public function test_index_filters_by_class_and_section_on_the_server(): void
    {
        $this->makeStudent($this->s['session'], $this->s['class6'], $this->s['sectionA'], ['full_name' => 'In A']);
        $this->makeStudent($this->s['session'], $this->s['class6'], $this->s['sectionB'], ['full_name' => 'In B'], '01700000002');
        $this->makeStudent($this->s['session'], $this->s['class7'], null, ['full_name' => 'In Seven'], '01700000003');

        $this->actingAs($this->ownerOf($this->school))
            ->get(route('students.index', ['class' => $this->s['class6']->id, 'section' => $this->s['sectionB']->id]))
            ->assertInertia(fn ($p) => $p->has('students.data', 1)->where('students.data.0.full_name', 'In B')->where('students.data.0.section', 'B'));
    }

    public function test_roles_limit_access(): void
    {
        $student = $this->makeStudent($this->s['session'], $this->s['class6']);

        $this->actingAs($this->userWithRole('Teacher'))->get(route('students.index'))->assertForbidden();
        $this->actingAs($this->userWithRole('Teacher'))->get(route('students.show', $student))->assertForbidden();
        $this->actingAs($this->userWithRole('Accountant'))->get(route('students.show', $student))->assertOk();
        $this->actingAs($this->userWithRole('Accountant'))->post(route('students.store'), $this->payload())->assertForbidden();
        $this->actingAs($this->userWithRole('Admission Officer'))->post(route('students.store'), $this->payload())->assertSessionHasNoErrors();
    }

    public function test_enrollment_history_is_preserved_on_promotion_and_graduation(): void
    {
        $student = $this->makeStudent($this->s['session'], $this->s['class6'], $this->s['sectionA'], roll: '3');
        $next = \App\Modules\Academics\Models\AcademicSession::create(['name' => '2027', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31']);
        $owner = $this->ownerOf($this->school);

        $this->actingAs($owner)->post(route('promotions.store'), [
            'from_session_id' => $this->s['session']->id, 'to_session_id' => $next->id,
            'to_class_id' => $this->s['class7']->id, 'to_section_id' => $this->s['class7A']->id,
            'mode' => 'promoted', 'student_ids' => [$student->id],
        ])->assertSessionHasNoErrors();

        $history = StudentEnrollment::query()->where('student_id', $student->id)->orderBy('id')->get();
        $this->assertSame(['promoted', 'enrolled'], $history->pluck('status')->all());
        $this->assertSame([$this->s['class6']->id, $this->s['class7']->id], $history->pluck('class_id')->all());
        $this->assertSame('3', $history[0]->roll_no, 'Old enrollment keeps its roll number.');
        $this->assertNotNull($history[0]->ended_at);

        // Promoting again from the same session is skipped, not duplicated.
        $this->actingAs($owner)->post(route('promotions.store'), [
            'from_session_id' => $this->s['session']->id, 'to_session_id' => $next->id,
            'to_class_id' => $this->s['class7']->id, 'mode' => 'promoted', 'student_ids' => [$student->id],
        ]);
        $this->assertSame(2, StudentEnrollment::query()->where('student_id', $student->id)->count());

        $this->actingAs($owner)->post(route('students.status', $student), ['outcome' => 'graduated'])->assertSessionHasNoErrors();
        $this->assertSame('graduated', $student->fresh()->status);
        $this->assertSame(['promoted', 'graduated'], StudentEnrollment::query()->where('student_id', $student->id)->orderBy('id')->pluck('status')->all());
    }

    public function test_repeat_keeps_the_student_in_the_same_class_next_session(): void
    {
        $student = $this->makeStudent($this->s['session'], $this->s['class6']);
        $next = \App\Modules\Academics\Models\AcademicSession::create(['name' => '2027', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31']);

        $this->actingAs($this->ownerOf($this->school))->post(route('promotions.store'), [
            'from_session_id' => $this->s['session']->id, 'to_session_id' => $next->id,
            'to_class_id' => $this->s['class6']->id, 'mode' => 'repeated', 'student_ids' => [$student->id],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['repeated', 'enrolled'], StudentEnrollment::query()->where('student_id', $student->id)->orderBy('id')->pluck('status')->all());
    }

    public function test_promotion_rejects_students_from_another_school(): void
    {
        $beta = $this->provisionSchool('beta');
        $betaStudent = $this->inSchool($beta, function () {
            $s = $this->academicStructure();

            return $this->makeStudent($s['session'], $s['class6']);
        });
        $this->onSchool($this->school);
        $next = \App\Modules\Academics\Models\AcademicSession::create(['name' => '2027', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31']);

        $this->actingAs($this->ownerOf($this->school))->post(route('promotions.store'), [
            'from_session_id' => $this->s['session']->id, 'to_session_id' => $next->id,
            'to_class_id' => $this->s['class7']->id, 'mode' => 'promoted', 'student_ids' => [$betaStudent->id],
        ])->assertSessionHasErrors('student_ids.0');

        $this->assertSame(1, $this->inSchool($beta, fn () => StudentEnrollment::query()->count()));
    }

    public function test_school_model_is_not_reassignable_through_shared_props(): void
    {
        // Sanity: the tenant's own School row is the only one visible as "school".
        $this->actingAs($this->ownerOf($this->school))
            ->get(route('students.index'))
            ->assertInertia(fn ($p) => $p->where('school.slug', 'alpha'));
        $this->assertInstanceOf(School::class, $this->tenant()->school());
    }
}
