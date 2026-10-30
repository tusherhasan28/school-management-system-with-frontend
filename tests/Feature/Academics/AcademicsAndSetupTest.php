<?php

namespace Tests\Feature\Academics;

use App\Enums\PlatformRole;
use App\Models\PlatformUser;
use App\Modules\Academics\Models\AcademicSession;
use App\Modules\Academics\Models\ClassSubject;
use App\Modules\Academics\Models\SchoolClass;
use App\Modules\Academics\Models\Section;
use App\Modules\Academics\Models\Subject;
use App\Modules\Setup\Services\SetupWizard;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\CreatesAcademicStructure;
use Tests\TenantTestCase;

class AcademicsAndSetupTest extends TenantTestCase
{
    use CreatesAcademicStructure;

    public function test_setup_wizard_is_resumable_and_creates_structure(): void
    {
        $owner = $this->ownerOf($this->school);

        $this->actingAs($owner)->get(route('dashboard'))->assertInertia(fn ($p) => $p->where('setup.completed', []));

        $this->actingAs($owner)->put(route('setup.profile'), [
            'name' => 'Alpha High School', 'name_bn' => 'আলফা উচ্চ বিদ্যালয়', 'institution_type' => 'school', 'locale' => 'bn',
            'slug' => 'hijacked', 'status' => 'active', // ignored: not whitelisted
        ])->assertSessionHasNoErrors();

        $this->actingAs($owner)->post(route('setup.session'), ['name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'])->assertSessionHasNoErrors();

        $this->actingAs($owner)->get(route('setup.show'))->assertInertia(fn ($p) => $p->where('progress.completed', ['profile', 'session']));

        $this->actingAs($owner)->post(route('setup.classes'), [
            'stage' => 'secondary',
            'classes' => [['name' => 'Class 6', 'name_bn' => 'ষষ্ঠ', 'sections' => ['A', 'B']], ['name' => 'Class 7', 'sections' => []]],
        ])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('setup.subjects'), ['subjects' => SetupWizard::subjectPreset('school')])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('setup.finish'))->assertRedirect(route('dashboard'));

        $school = $this->school->fresh();
        $this->assertSame(['Alpha High School', 'alpha', 'bn'], [$school->name, $school->slug, $school->locale]);
        $this->assertSame('2026', AcademicSession::current()?->name);
        $this->assertSame(['Class 6', 'Class 7'], SchoolClass::query()->orderBy('level_order')->pluck('name')->all());
        $this->assertSame(2, Section::query()->count());
        $this->assertSame(7, Subject::query()->count());
        $this->actingAs($owner)->get(route('dashboard'))->assertInertia(fn ($p) => $p->where('setup', null));
    }

    public function test_class_presets_follow_institution_type(): void
    {
        $this->assertSame('Play', SetupWizard::classPreset('kindergarten')['classes'][0]['name']);
        $this->assertSame('HSC 1st Year', SetupWizard::classPreset('college')['classes'][0]['name']);
        $this->assertSame('দাখিল ৬', SetupWizard::classPreset('madrasa')['classes'][5]['name_bn']);
        $this->assertCount(10, SetupWizard::classPreset('school')['classes']);
    }

    public function test_only_one_current_session(): void
    {
        $s = $this->academicStructure();
        $next = AcademicSession::create(['name' => '2027', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31']);

        $this->actingAs($this->ownerOf($this->school))->post(route('academics.sessions.current', $next))->assertSessionHasNoErrors();

        $this->assertSame(['2027'], AcademicSession::query()->where('is_current', true)->pluck('name')->all());
        $this->assertFalse($s['session']->fresh()->is_current);
    }

    public function test_class_with_students_cannot_be_deleted(): void
    {
        $s = $this->academicStructure();
        $this->makeStudent($s['session'], $s['class6'], $s['sectionA']);
        $owner = $this->ownerOf($this->school);

        $this->actingAs($owner)->delete(route('academics.classes.destroy', $s['class6']))->assertSessionHasErrors('class');
        $this->actingAs($owner)->delete(route('academics.sections.destroy', $s['sectionA']))->assertSessionHasErrors('section');
        $this->actingAs($owner)->delete(route('academics.classes.destroy', $s['class7']))->assertSessionHasNoErrors();
        $this->assertSame(1, SchoolClass::query()->count());
    }

    public function test_class_subjects_sync_and_reject_foreign_subjects(): void
    {
        $s = $this->academicStructure();
        $bangla = Subject::create(['name' => 'Bangla', 'code' => 'BAN']);
        $math = Subject::create(['name' => 'Mathematics', 'code' => 'MATH']);
        $owner = $this->ownerOf($this->school);
        $url = route('academics.class-subjects.update', $s['class6']);

        $this->actingAs($owner)->put($url, ['academic_session_id' => $s['session']->id, 'subjects' => [
            ['subject_id' => $bangla->id, 'full_marks' => 100, 'pass_marks' => 33],
            ['subject_id' => $math->id, 'full_marks' => 100, 'pass_marks' => 120],
        ]])->assertSessionHasErrors('subjects.1.pass_marks');

        $this->actingAs($owner)->put($url, ['academic_session_id' => $s['session']->id, 'subjects' => [
            ['subject_id' => $bangla->id, 'full_marks' => 100, 'pass_marks' => 33],
            ['subject_id' => $math->id, 'full_marks' => 50, 'pass_marks' => 17, 'is_optional' => true],
        ]])->assertSessionHasNoErrors();
        $this->assertSame(2, ClassSubject::query()->count());
        $this->assertSame('17.00', ClassSubject::query()->where('subject_id', $math->id)->value('pass_marks'));

        // Removing a subject from the list deletes the mapping.
        $this->actingAs($owner)->put($url, ['academic_session_id' => $s['session']->id, 'subjects' => [['subject_id' => $bangla->id, 'full_marks' => 100, 'pass_marks' => 33]]]);
        $this->assertSame([$bangla->id], ClassSubject::query()->pluck('subject_id')->all());

        $beta = $this->provisionSchool('beta');
        $betaSubject = $this->inSchool($beta, fn () => Subject::create(['name' => 'Beta Subject']));
        $this->onSchool($this->school);
        $this->actingAs($owner)->put($url, ['academic_session_id' => $s['session']->id, 'subjects' => [['subject_id' => $betaSubject->id, 'full_marks' => 100, 'pass_marks' => 33]]])
            ->assertSessionHasErrors('subjects.0.subject_id');
    }

    public function test_names_are_unique_per_school_only_and_cross_school_ids_404(): void
    {
        $s = $this->academicStructure();
        $beta = $this->provisionSchool('beta');
        $betaClass = $this->inSchool($beta, fn () => SchoolClass::create(['name' => 'Class 6', 'level_order' => 6, 'education_stage' => 'secondary']));
        $this->onSchool($this->school);
        $owner = $this->ownerOf($this->school);

        $this->actingAs($owner)->post(route('academics.classes.store'), ['name' => 'Class 6', 'level_order' => 6, 'education_stage' => 'secondary'])->assertSessionHasErrors('name');
        $this->actingAs($owner)->put(route('academics.classes.update', $betaClass->id), ['name' => 'X', 'level_order' => 1, 'education_stage' => 'other'])->assertNotFound();
        $this->actingAs($owner)->post(route('academics.sections.store', $betaClass->id), ['name' => 'Z'])->assertNotFound();
        $this->actingAs($owner)->get(route('academics.class-subjects.edit', $betaClass->id))->assertNotFound();
        $this->assertSame('Class 6', $this->inSchool($beta, fn () => SchoolClass::query()->find($betaClass->id)->name));
    }

    public function test_teachers_can_view_but_not_change_academics(): void
    {
        $this->academicStructure();
        $teacher = $this->userWithRole('Teacher');

        $this->actingAs($teacher)->get(route('academics.index'))->assertOk()->assertInertia(fn ($p) => $p->where('can.manage', false));
        $this->actingAs($teacher)->post(route('academics.subjects.store'), ['name' => 'X', 'subject_type' => 'compulsory'])->assertForbidden();
        $this->actingAs($teacher)->get(route('setup.show'))->assertForbidden();
    }

    public function test_horizon_is_only_for_platform_admins_on_the_admin_host(): void
    {
        $this->get($this->schoolUrl($this->school, '/horizon'))->assertNotFound();
        $this->actingAs($this->ownerOf($this->school))->get($this->schoolUrl($this->school, '/horizon'))->assertNotFound();

        $this->flushSession();
        URL::forceRootUrl(null); // TenantTestCase pins generated URLs to the school host
        $this->get($this->platformUrl('/horizon'))->assertRedirect($this->platformUrl('/platform/login'));
        $this->actingAs(PlatformUser::factory()->role(PlatformRole::SupportAgent)->create(), 'platform')->get($this->platformUrl('/horizon'))->assertForbidden();
        $this->actingAs(PlatformUser::factory()->create(), 'platform')->get($this->platformUrl('/horizon'))->assertOk();
    }
}
