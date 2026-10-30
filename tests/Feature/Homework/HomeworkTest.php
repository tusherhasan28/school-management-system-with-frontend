<?php

namespace Tests\Feature\Homework;

use App\Modules\Homework\Models\Homework;
use App\Modules\Homework\Models\HomeworkSubmission;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAcademicStructure;
use Tests\Concerns\CreatesTeachers;
use Tests\TenantTestCase;

class HomeworkTest extends TenantTestCase
{
    use CreatesAcademicStructure, CreatesTeachers;

    /** @var array<string, mixed> */
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->s = $this->academicStructure();
        $this->makeStudent($this->s['session'], $this->s['class6'], $this->s['sectionA'], ['full_name' => 'A1']);
        $this->makeStudent($this->s['session'], $this->s['class6'], $this->s['sectionA'], ['full_name' => 'A2'], '01722000000');
        $this->makeStudent($this->s['session'], $this->s['class6'], $this->s['sectionB'], ['full_name' => 'B1'], '01733000000');
    }

    private function form(array $o = []): array
    {
        return $o + [
            'class_id' => $this->s['class6']->id, 'section_id' => $this->s['sectionA']->id,
            'subject_id' => $this->subjectFor($this->s['session'], $this->s['class6'], 'Math')->id,
            'title' => 'Fractions', 'instructions' => 'Q1–10', 'assigned_on' => '2026-03-10', 'due_at' => '2026-03-12 09:00',
            'max_marks' => 10, 'status' => 'published',
        ];
    }

    public function test_teacher_sets_homework_for_their_subject_with_a_private_attachment(): void
    {
        [$user, $staff] = $this->teacher();
        $math = $this->subjectFor($this->s['session'], $this->s['class6'], 'Math');
        $this->assign($staff, $this->s['session'], $this->s['class6'], $this->s['sectionA']->id, $math);
        [, $impostor] = $this->teacher(name: 'Other');

        $this->actingAs($user)->post(route('homework.store'), $this->form([
            'staff_member_id' => $impostor->id, // ignored
            'attachment' => UploadedFile::fake()->create('worksheet.pdf', 120, 'application/pdf'),
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $homework = Homework::query()->sole();
        $this->assertSame($staff->id, $homework->staff_member_id);
        $this->assertStringStartsWith('schools/'.$this->school->id.'/homework/', $homework->attachment_path);
        Storage::disk('local')->assertExists($homework->attachment_path);
        $this->assertSame(2, HomeworkSubmission::query()->where('homework_id', $homework->id)->count(), 'One row per student in section A.');

        $this->actingAs($user)->get(route('homework.attachment', $homework))->assertOk()->assertDownload('worksheet.pdf');
    }

    public function test_teacher_cannot_set_homework_outside_assignments(): void
    {
        [$user, $staff] = $this->teacher();
        $this->assign($staff, $this->s['session'], $this->s['class6'], $this->s['sectionA']->id, $this->subjectFor($this->s['session'], $this->s['class6'], 'Math'));
        $english = $this->subjectFor($this->s['session'], $this->s['class6'], 'English');

        $this->actingAs($user)->post(route('homework.store'), $this->form(['subject_id' => $english->id]))->assertSessionHasErrors('subject_id');
        $this->actingAs($user)->post(route('homework.store'), $this->form(['section_id' => $this->s['sectionB']->id]))->assertSessionHasErrors('subject_id');
        $this->actingAs($user)->post(route('homework.store'), $this->form(['section_id' => null]))->assertSessionHasErrors('subject_id');
        $this->assertSame(0, Homework::query()->count());
    }

    public function test_grading_validates_marks_and_records_the_grader(): void
    {
        [$user, $staff] = $this->teacher();
        $this->assign($staff, $this->s['session'], $this->s['class6'], $this->s['sectionA']->id, $this->subjectFor($this->s['session'], $this->s['class6'], 'Math'));
        $this->actingAs($user)->post(route('homework.store'), $this->form());
        $homework = Homework::query()->sole();
        [$first, $second] = HomeworkSubmission::query()->orderBy('id')->get();

        $this->actingAs($user)->post(route('homework.grade', $homework), ['rows' => [$first->student_id => ['status' => 'graded', 'marks' => 12]]])
            ->assertSessionHasErrors('rows.'.$first->student_id.'.marks');

        $this->actingAs($user)->post(route('homework.grade', $homework), ['rows' => [
            $first->student_id => ['status' => 'graded', 'marks' => 8.5, 'feedback' => 'Good'],
            $second->student_id => ['status' => 'missing'],
        ]])->assertSessionHasNoErrors();

        $first->refresh();
        $this->assertSame(['graded', '8.50', 'Good', $user->id], [$first->status, $first->marks, $first->feedback, $first->graded_by]);
        $this->assertNotNull($first->submitted_at);
        $this->assertSame('missing', $second->fresh()->status);

        // A student from another homework/section cannot be smuggled in.
        $b1 = \App\Modules\People\Models\Student::query()->where('full_name', 'B1')->sole();
        $this->actingAs($user)->post(route('homework.grade', $homework), ['rows' => [$b1->id => ['status' => 'graded', 'marks' => 5]]])->assertSessionHasErrors('rows');
    }

    public function test_list_shows_own_homework_to_teachers_and_everything_to_principal(): void
    {
        [$user, $staff] = $this->teacher();
        $math = $this->subjectFor($this->s['session'], $this->s['class6'], 'Math');
        $this->assign($staff, $this->s['session'], $this->s['class6'], $this->s['sectionA']->id, $math);
        $this->actingAs($user)->post(route('homework.store'), $this->form());
        $this->actingAs($this->ownerOf($this->school))->post(route('homework.store'), $this->form(['section_id' => $this->s['sectionB']->id, 'title' => 'B work']));
        $other = Homework::query()->where('title', 'B work')->sole();

        $this->actingAs($user)->get(route('homework.index'))->assertInertia(fn ($p) => $p->has('homework.data', 1)->where('homework.data.0.title', 'Fractions'));
        $this->actingAs($user)->get(route('homework.show', $other))->assertNotFound();
        $this->actingAs($user)->post(route('homework.grade', $other), ['rows' => []])->assertNotFound();
        $this->actingAs($this->userWithRole('Principal'))->get(route('homework.index'))->assertInertia(fn ($p) => $p->has('homework.data', 2));
    }

    public function test_other_schools_homework_and_files_are_unreachable(): void
    {
        $this->actingAs($this->ownerOf($this->school))->post(route('homework.store'), $this->form(['attachment' => UploadedFile::fake()->create('secret.pdf', 10)]));
        $homework = Homework::query()->sole();

        $beta = $this->provisionSchool('beta');
        $this->flushSession();
        $betaOwner = $this->ownerOf($beta);

        $this->actingAs($betaOwner)->get($this->schoolUrl($beta, '/app/homework/'.$homework->id))->assertNotFound();
        $this->actingAs($betaOwner)->get($this->schoolUrl($beta, '/app/homework/'.$homework->id.'/attachment'))->assertNotFound();
        $this->actingAs($betaOwner)->post($this->schoolUrl($beta, '/app/homework/'.$homework->id.'/grade'), ['rows' => []])->assertNotFound();
    }
}
