<?php

namespace Tests\Feature\Exams;

use App\Models\User;
use App\Modules\Exams\Jobs\NotifyResultPublished;
use App\Modules\Exams\Models\Examination;
use App\Modules\Exams\Models\ExamMarkSheet;
use App\Modules\Exams\Models\ExamResult;
use App\Modules\Exams\Models\ExamSubject;
use App\Modules\Exams\Models\GradingScale;
use App\Modules\Exams\Models\ResultCalculation;
use App\Modules\Messaging\Mail\SchoolMessage;
use App\Modules\Exams\Services\GradingScaleService;
use App\Modules\Exams\Services\ResultService;
use App\Modules\People\Models\Student;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesAcademicStructure;
use Tests\Concerns\CreatesTeachers;
use Tests\TenantTestCase;

/**
 * Exam setup → marks entry → verification → calculation → approval →
 * publication, with permissions, versioning and tenant isolation.
 */
class ExamWorkflowTest extends TenantTestCase
{
    use CreatesAcademicStructure, CreatesTeachers;

    /** @var array<string, mixed> */
    private array $s;

    private User $owner;

    /** @var array<string, Student> */
    private array $st = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = $this->academicStructure();
        $this->owner = $this->ownerOf($this->school);

        foreach (['Bangla', 'English', 'Math'] as $name) {
            $this->subjectFor($this->s['session'], $this->s['class6'], $name);
        }

        $this->st['s1'] = $this->makeStudent($this->s['session'], $this->s['class6'], $this->s['sectionA'], ['full_name' => 'S1'], '01711000001', '1');
        $this->st['s2'] = $this->makeStudent($this->s['session'], $this->s['class6'], $this->s['sectionA'], ['full_name' => 'S2'], '01711000002', '2');
        $this->st['s3'] = $this->makeStudent($this->s['session'], $this->s['class6'], $this->s['sectionA'], ['full_name' => 'S3'], '01711000003', '3');
        $this->st['s4'] = $this->makeStudent($this->s['session'], $this->s['class6'], $this->s['sectionB'], ['full_name' => 'S4'], '01711000004', '1');
    }

    private function scale(): GradingScale
    {
        return app(GradingScaleService::class)->fromPreset('gpa5');
    }

    /** Draft exam for Class 6 with Written 70 + MCQ 30 per subject. */
    private function exam(?GradingScale $scale = null): Examination
    {
        $scale ??= $this->scale();
        $this->actingAs($this->owner)->post(route('exams.store'), [
            'name' => 'Half-Yearly', 'academic_session_id' => $this->s['session']->id, 'grading_scale_id' => $scale->id,
            'starts_on' => '2026-06-01', 'ends_on' => '2026-06-15',
        ])->assertSessionHasNoErrors();
        $exam = Examination::query()->latest('id')->firstOrFail();

        $this->actingAs($this->owner)->post(route('exams.subjects.store', $exam), ['class_id' => $this->s['class6']->id, 'template' => 'written_mcq'])
            ->assertSessionHasNoErrors();

        return $exam;
    }

    private function sheet(Examination $exam, string $subject, string $section = 'sectionA'): ExamMarkSheet
    {
        $examSubject = ExamSubject::query()->where('examination_id', $exam->id)->whereHas('subject', fn ($q) => $q->where('name', $subject))->sole();

        return ExamMarkSheet::query()->where('exam_subject_id', $examSubject->id)->where('section_id', $this->s[$section]->id)->sole();
    }

    /** @param  array<string, array{0: string|null, 1: string|null}|string>  $marks  student key => [written, mcq] or a status */
    private function fill(Examination $exam, string $subject, string $section, array $marks, ?User $as = null, bool $submit = false)
    {
        $sheet = $this->sheet($exam, $subject, $section);
        [$written, $mcq] = $sheet->examSubject->components()->pluck('id')->all();
        $rows = [];

        foreach ($marks as $key => $m) {
            $rows[$this->st[$key]->id] = is_string($m) ? ['status' => $m] : ['status' => 'present', 'parts' => [$written => $m[0], $mcq => $m[1]]];
        }

        return $this->actingAs($as ?? $this->owner)->post(route('marks.save', $sheet), ['rows' => $rows, 'submit' => $submit]);
    }

    /** Enter, submit and verify every sheet with the fixture marks. */
    private function enterAll(Examination $exam): void
    {
        $this->actingAs($this->owner)->post(route('exams.open', $exam))->assertSessionHasNoErrors();

        $a = [
            'Bangla' => ['s1' => ['60', '25'], 's2' => ['40', '20'], 's3' => ['70', '30']],
            'English' => ['s1' => ['50', '22'], 's2' => ['30', '10'], 's3' => 'absent'],
            'Math' => ['s1' => ['45', '20'], 's2' => ['50', '25'], 's3' => ['70', '30']],
        ];

        foreach ($a as $subject => $marks) {
            $this->fill($exam, $subject, 'sectionA', $marks, submit: true)->assertSessionHasNoErrors();
            $this->fill($exam, $subject, 'sectionB', ['s4' => ['56', '24']], submit: true)->assertSessionHasNoErrors();
        }

        $this->actingAs($this->owner)->post(route('exams.verify-all', $exam))->assertSessionHasNoErrors();
    }

    public function test_full_workflow_produces_expected_results(): void
    {
        Queue::fake();
        $exam = $this->exam();
        $this->enterAll($exam);

        $this->actingAs($this->owner)->post(route('exams.calculate', $exam))->assertSessionHasNoErrors();
        $exam->refresh();
        $this->assertSame('calculated', $exam->status);

        $results = ExamResult::query()->where('result_calculation_id', $exam->current_calculation_id)->get()->keyBy('student_id');
        $row = fn (string $key) => $results[$this->st[$key]->id]->only(['gpa', 'letter_grade', 'result_status', 'obtained_marks', 'percentage', 'class_position', 'section_position']);

        $this->assertSame(['gpa' => '4.17', 'letter_grade' => 'A', 'result_status' => 'pass', 'obtained_marks' => '222.00', 'percentage' => '74.00', 'class_position' => 2, 'section_position' => 1], $row('s1'));
        $this->assertSame(['gpa' => '3.17', 'letter_grade' => 'B', 'result_status' => 'pass', 'obtained_marks' => '175.00', 'percentage' => '58.33', 'class_position' => 3, 'section_position' => 2], $row('s2'));
        $this->assertSame(['gpa' => '0.00', 'letter_grade' => 'F', 'result_status' => 'fail', 'obtained_marks' => '200.00', 'percentage' => '66.66', 'class_position' => null, 'section_position' => null], $row('s3'));
        $this->assertSame(['gpa' => '5.00', 'letter_grade' => 'A+', 'result_status' => 'pass', 'obtained_marks' => '240.00', 'percentage' => '80.00', 'class_position' => 1, 'section_position' => 1], $row('s4'));

        $calculation = ResultCalculation::query()->findOrFail($exam->current_calculation_id);
        $this->assertSame([1, '1.0.0', 4], [$calculation->version, $calculation->engine_version, $calculation->student_count]);
        $this->assertTrue(app(ResultService::class)->reproduces($calculation));

        $this->actingAs($this->owner)->get(route('exams.results', ['exam' => $exam, 'class' => $this->s['class6']->id]))
            ->assertOk()->assertInertia(fn ($p) => $p->has('rows', 4)->where('rows.0.name', 'S4')->where('stats.pass', 3));
        $this->actingAs($this->owner)->get(route('exams.report-cards', ['exam' => $exam, 'class' => $this->s['class6']->id, 'section' => $this->s['sectionA']->id]))
            ->assertOk()->assertInertia(fn ($p) => $p->has('cards', 3)->where('cards.0.student.roll', '1')->has('cards.0.subjects', 3)->where('exam.published', false));

        $this->actingAs($this->owner)->post(route('exams.publish', $exam))->assertSessionHasErrors('exam'); // not approved yet
        $this->actingAs($this->owner)->post(route('exams.approve', $exam))->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('exams.publish', $exam), ['notify' => true])->assertSessionHasNoErrors();

        $this->assertSame('published', $exam->refresh()->status);
        $this->assertNotNull($exam->gradingScale->locked_at);
        Queue::assertPushed(NotifyResultPublished::class, fn ($job) => $job->examinationId === $exam->id);
        $this->assertTrue(ActivityLog::query()->where('action', 'exam.results_published')->exists());
    }

    public function test_setup_is_frozen_once_marks_entry_opens(): void
    {
        $exam = $this->exam();
        $subject = ExamSubject::query()->where('examination_id', $exam->id)->firstOrFail();
        $components = $subject->components->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'full_marks' => $c->full_marks, 'pass_marks' => null])->all();
        $components[0]['full_marks'] = '60';
        $components[] = ['name' => 'Practical', 'full_marks' => '10', 'pass_marks' => '4'];

        $this->actingAs($this->owner)->put(route('exams.subjects.update', $subject), ['subject_type' => 'compulsory', 'pass_marks' => '33', 'components' => $components])->assertSessionHasNoErrors();
        $this->assertSame(['100.00', 3], [$subject->refresh()->full_marks, $subject->components()->count()]);

        $this->actingAs($this->owner)->put(route('exams.subjects.update', $subject), ['subject_type' => 'compulsory', 'pass_marks' => '150', 'components' => $components])->assertSessionHasErrors('pass_marks');

        $this->actingAs($this->owner)->post(route('exams.open', $exam))->assertSessionHasNoErrors();
        $this->assertSame(6, ExamMarkSheet::query()->count()); // 3 subjects × 2 sections

        $components[0]['full_marks'] = '90';
        $this->actingAs($this->owner)->put(route('exams.subjects.update', $subject), ['subject_type' => 'optional', 'pass_marks' => '10', 'exam_date' => '2026-06-03', 'components' => $components]);
        $subject->refresh();
        $this->assertSame(['compulsory', '33.00', '100.00', '2026-06-03'], [$subject->subject_type, $subject->pass_marks, $subject->full_marks, $subject->exam_date->toDateString()]);

        $this->actingAs($this->owner)->post(route('exams.subjects.store', $exam), ['class_id' => $this->s['class6']->id, 'template' => 'written'])->assertSessionHasErrors('exam');
        $this->actingAs($this->owner)->delete(route('exams.subjects.destroy', $subject))->assertSessionHasErrors('exam');
    }

    public function test_marks_are_validated_and_changes_audited(): void
    {
        $exam = $this->exam();
        $this->actingAs($this->owner)->post(route('exams.open', $exam));

        $this->fill($exam, 'Math', 'sectionA', ['s1' => ['71', '10']])->assertSessionHasErrors();       // written is out of 70
        $this->fill($exam, 'Math', 'sectionA', ['s1' => ['50.555', '10']])->assertSessionHasErrors();   // three decimals
        $this->fill($exam, 'Math', 'sectionA', ['s4' => ['50', '10']])->assertSessionHasErrors('rows'); // S4 is in section B

        $this->fill($exam, 'Math', 'sectionA', ['s1' => ['50', '10'], 's2' => ['40', null], 's3' => 'exempt'])->assertSessionHasNoErrors();
        $sheet = $this->sheet($exam, 'Math', 'sectionA');
        $this->assertSame('60.00', $sheet->marks()->where('student_id', $this->st['s1']->id)->value('total'));
        $this->assertNull($sheet->marks()->where('student_id', $this->st['s2']->id)->value('total'), 'Incomplete rows keep no total.');

        $this->actingAs($this->owner)->post(route('marks.verify', $sheet))->assertSessionHasErrors('sheet'); // S2 incomplete

        $this->fill($exam, 'Math', 'sectionA', ['s1' => ['55', '10'], 's2' => ['40', '5']], submit: true)->assertSessionHasNoErrors();
        $log = ActivityLog::query()->where('action', 'exam.marks_saved')->latest('id')->firstOrFail();
        $change = collect($log->new_values['changed'])->firstWhere('student_id', $this->st['s1']->id);
        $this->assertEquals(['student_id' => $this->st['s1']->id, 'old' => ['present', '60.00'], 'new' => ['present', '65.00']], $change);

        $this->fill($exam, 'Math', 'sectionA', ['s1' => ['20', '10']])->assertSessionHasErrors('marks'); // submitted: locked
        $this->actingAs($this->owner)->post(route('marks.return', $sheet), ['reason' => 'x'])->assertSessionHasErrors('reason');
        $this->actingAs($this->owner)->post(route('marks.return', $sheet), ['reason' => 'Wrong MCQ for roll 1'])->assertSessionHasNoErrors();
        $this->fill($exam, 'Math', 'sectionA', ['s1' => ['20', '10']])->assertSessionHasNoErrors();
    }

    public function test_teachers_enter_marks_only_for_their_subject_and_section(): void
    {
        $exam = $this->exam();
        $this->actingAs($this->owner)->post(route('exams.open', $exam));
        [$user, $staff] = $this->teacher();
        $math = \App\Modules\Academics\Models\Subject::query()->where('name', 'Math')->sole();
        $this->assign($staff, $this->s['session'], $this->s['class6'], $this->s['sectionA']->id, $math);

        $this->fill($exam, 'Math', 'sectionA', ['s1' => ['50', '20']], $user)->assertSessionHasNoErrors();
        $this->fill($exam, 'English', 'sectionA', ['s1' => ['50', '20']], $user)->assertForbidden();
        $this->fill($exam, 'Math', 'sectionB', ['s4' => ['50', '20']], $user)->assertForbidden();

        $this->actingAs($user)->get(route('marks.index'))->assertOk()->assertInertia(fn ($p) => $p->has('sheets', 1)->where('sheets.0.subject', 'Math'));
        $this->actingAs($user)->get(route('marks.show', $this->sheet($exam, 'English')))->assertForbidden();
        $this->actingAs($user)->post(route('marks.verify', $this->sheet($exam, 'Math')))->assertForbidden();
        $this->actingAs($user)->get(route('exams.index'))->assertForbidden();
        $this->actingAs($user)->post(route('exams.calculate', $exam))->assertForbidden();

        // A class teacher covers every subject of their own section.
        [$ct, $ctStaff] = $this->teacher('Class Teacher', 'CT');
        $this->assign($ctStaff, $this->s['session'], $this->s['class6'], $this->s['sectionB']->id, null, 'class_teacher');
        $this->fill($exam, 'English', 'sectionB', ['s4' => ['50', '20']], $ct)->assertSessionHasNoErrors();
        $this->fill($exam, 'English', 'sectionA', ['s1' => ['50', '20']], $ct)->assertForbidden();
    }

    public function test_recalculation_creates_a_new_version_and_keeps_the_old_one(): void
    {
        $exam = $this->exam();
        $this->actingAs($this->owner)->post(route('exams.calculate', $exam))->assertSessionHasErrors('exam'); // still a draft
        $this->enterAll($exam);

        $this->actingAs($this->owner)->post(route('exams.calculate', $exam))->assertSessionHasNoErrors();
        $v1 = $exam->refresh()->current_calculation_id;

        $this->actingAs($this->owner)->post(route('exams.reopen', $exam), ['reason' => 'English absentee sat a make-up paper'])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('marks.return', $this->sheet($exam, 'English')), ['reason' => 'Make-up paper marks'])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('exams.calculate', $exam))->assertSessionHasErrors('exam'); // a sheet is unverified

        $this->fill($exam, 'English', 'sectionA', ['s1' => ['50', '22'], 's2' => ['30', '10'], 's3' => ['60', '20']], submit: true)->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('marks.verify', $this->sheet($exam, 'English')))->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('exams.calculate', $exam))->assertSessionHasNoErrors();
        $exam->refresh();

        $this->assertNotSame($v1, $exam->current_calculation_id);
        $this->assertSame([1, 2], ResultCalculation::query()->where('examination_id', $exam->id)->orderBy('version')->pluck('version')->all());
        $this->assertSame('fail', ExamResult::query()->where('result_calculation_id', $v1)->where('student_id', $this->st['s3']->id)->value('result_status'));
        $this->assertSame('pass', ExamResult::query()->where('result_calculation_id', $exam->current_calculation_id)->where('student_id', $this->st['s3']->id)->value('result_status'));
        $this->assertFalse(app(ResultService::class)->reproduces(ResultCalculation::query()->findOrFail($v1)), 'Marks changed since version 1.');

        $this->expectException(\LogicException::class);
        ResultCalculation::query()->findOrFail($v1)->update(['student_count' => 0]);
    }

    public function test_changing_the_scale_after_calculation_blocks_approval(): void
    {
        $scale = $this->scale();
        $exam = $this->exam($scale);
        $this->enterAll($exam);
        $this->actingAs($this->owner)->post(route('exams.calculate', $exam));

        $items = $scale->items->map->only(['grade', 'min_percentage', 'max_percentage', 'grade_point', 'is_fail'])->values()->all();
        $this->actingAs($this->owner)->put(route('grading-scales.update', $scale), ['name' => $scale->name, 'items' => $items, 'rules' => ['gpa_rounding' => 'truncate'] + $scale->rules])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->post(route('exams.approve', $exam))->assertSessionHasErrors('exam');
        $this->actingAs($this->owner)->post(route('exams.calculate', $exam))->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('exams.approve', $exam))->assertSessionHasNoErrors();

        // S1: 12.50 ÷ 3 truncated
        $this->assertSame('4.16', ExamResult::query()->where('result_calculation_id', $exam->refresh()->current_calculation_id)->where('student_id', $this->st['s1']->id)->value('gpa'));
    }

    public function test_grading_scale_validation_lock_and_duplicate(): void
    {
        $scale = $this->scale();
        $items = $scale->items->map->only(['grade', 'min_percentage', 'max_percentage', 'grade_point', 'is_fail'])->values()->all();
        $gap = $items;
        $gap[1]['max_percentage'] = '75.00';

        $this->actingAs($this->owner)->put(route('grading-scales.update', $scale), ['name' => 'X', 'items' => $gap, 'rules' => $scale->rules])->assertSessionHasErrors('items');

        $scale->forceFill(['locked_at' => now()])->save();
        $this->actingAs($this->owner)->put(route('grading-scales.update', $scale), ['name' => 'X', 'items' => $items, 'rules' => $scale->rules])->assertSessionHasErrors('scale');
        $this->actingAs($this->owner)->delete(route('grading-scales.destroy', $scale))->assertSessionHasErrors('scale');

        $this->actingAs($this->owner)->post(route('grading-scales.duplicate', $scale))->assertRedirect();
        $copy = GradingScale::query()->latest('id')->firstOrFail();
        $this->assertSame([false, 7, 'GPA 5 (secondary) (2)'], [$copy->isLocked(), $copy->items()->count(), $copy->name]);

        $this->actingAs($this->userWithRole('Teacher'))->get(route('exams.setup'))->assertForbidden();
    }

    public function test_approval_and_publication_permissions(): void
    {
        $exam = $this->exam();
        $this->enterAll($exam);
        $this->actingAs($this->owner)->post(route('exams.calculate', $exam));

        $controller = $this->userWithRole('Exam Controller');
        $principal = $this->userWithRole('Principal');

        $this->actingAs($controller)->post(route('exams.approve', $exam))->assertForbidden();
        $this->actingAs($this->userWithRole('Teacher'))->post(route('exams.approve', $exam))->assertForbidden();
        $this->actingAs($principal)->post(route('exams.approve', $exam))->assertSessionHasNoErrors();
        $this->actingAs($controller)->post(route('exams.publish', $exam))->assertForbidden();
        $this->actingAs($principal)->post(route('exams.publish', $exam))->assertSessionHasNoErrors();

        // Reopening a published result needs the publish permission and a reason.
        $this->actingAs($controller)->post(route('exams.reopen', $exam), ['reason' => 'Mistake in Math'])->assertForbidden();
        $this->actingAs($principal)->post(route('exams.reopen', $exam), [])->assertSessionHasErrors('reason');
        $this->actingAs($principal)->post(route('exams.reopen', $exam), ['reason' => 'Mistake in Math'])->assertSessionHasNoErrors();
        $this->assertSame(['marks_entry', null], [$exam->refresh()->status, $exam->published_at]);
        $this->assertSame('Mistake in Math', ActivityLog::query()->where('action', 'exam.reopened')->latest('id')->value('new_values')['reason']);
    }

    public function test_results_are_limited_to_the_class_teachers_section(): void
    {
        $exam = $this->exam();
        $this->enterAll($exam);
        $this->actingAs($this->owner)->post(route('exams.calculate', $exam));

        [$ct, $staff] = $this->teacher('Class Teacher', 'CT');
        $this->assign($staff, $this->s['session'], $this->s['class6'], $this->s['sectionA']->id, null, 'class_teacher');

        $this->actingAs($ct)->get(route('exams.results', ['exam' => $exam]))
            ->assertOk()->assertInertia(fn ($p) => $p->where('filters.section', $this->s['sectionA']->id)->has('rows', 3));
        $this->actingAs($ct)->get(route('exams.results', ['exam' => $exam, 'class' => $this->s['class6']->id, 'section' => $this->s['sectionB']->id]))->assertForbidden();
        $this->actingAs($ct)->get(route('exams.report-cards', ['exam' => $exam, 'class' => $this->s['class6']->id, 'section' => $this->s['sectionA']->id, 'student' => $this->st['s1']->id]))->assertOk();
        $this->actingAs($ct)->get(route('exams.report-cards', ['exam' => $exam, 'class' => $this->s['class6']->id, 'section' => $this->s['sectionA']->id, 'student' => $this->st['s4']->id]))->assertNotFound();

        [$subjectTeacher] = $this->teacher();
        $this->actingAs($subjectTeacher)->get(route('exams.results', ['exam' => $exam]))->assertForbidden();
        $this->actingAs($this->userWithRole('Principal'))->get(route('exams.results', ['exam' => $exam]))->assertInertia(fn ($p) => $p->has('rows', 4));
    }

    public function test_student_profile_shows_drafts_only_to_result_viewers(): void
    {
        $exam = $this->exam();
        $this->enterAll($exam);
        $this->actingAs($this->owner)->post(route('exams.calculate', $exam));

        $officer = $this->userWithRole('Admission Officer'); // may view students, not results
        $principal = $this->userWithRole('Principal');
        $profile = route('students.show', $this->st['s1']);

        $this->actingAs($officer)->get($profile)->assertInertia(fn ($p) => $p->has('results', 0));
        $this->actingAs($principal)->get($profile)->assertInertia(fn ($p) => $p->has('results', 1)->where('results.0.published', false)->where('results.0.gpa', '4.17'));

        $this->actingAs($this->owner)->post(route('exams.approve', $exam));
        $this->actingAs($this->owner)->post(route('exams.publish', $exam));

        $this->actingAs($officer)->get($profile)->assertInertia(fn ($p) => $p->has('results', 1)->where('results.0.published', true)->where('results.0.can_print', false));
    }

    public function test_guardians_are_emailed_when_results_are_published(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $exam = $this->exam();
        $this->enterAll($exam);
        $this->st['s1']->guardians()->first()->update(['email' => 'parent1@example.test']);
        $this->actingAs($this->owner)->post(route('exams.calculate', $exam));
        $this->actingAs($this->owner)->post(route('exams.approve', $exam));
        $this->actingAs($this->owner)->post(route('exams.publish', $exam), ['notify' => true]); // queue is sync in tests

        \Illuminate\Support\Facades\Mail::assertSent(SchoolMessage::class, fn ($m) => $m->hasTo('parent1@example.test')
            && str_contains($m->text, 'S1') && str_contains($m->text, 'GPA 4.17') && str_contains($m->text, 'Half-Yearly'));
        \Illuminate\Support\Facades\Mail::assertSentCount(1);
    }

    public function test_other_schools_cannot_reach_exams_or_sheets(): void
    {
        $exam = $this->exam();
        $this->actingAs($this->owner)->post(route('exams.open', $exam));
        $sheet = $this->sheet($exam, 'Math');
        $scaleId = $exam->grading_scale_id;

        $beta = $this->provisionSchool('beta');
        $this->flushSession();
        $betaOwner = $this->ownerOf($beta);

        foreach (["/app/exams/{$exam->id}", "/app/exams/{$exam->id}/results", "/app/marks/{$sheet->id}", "/app/grading-scales/{$scaleId}"] as $path) {
            $this->actingAs($betaOwner)->get($this->schoolUrl($beta, $path))->assertNotFound();
        }

        $this->actingAs($betaOwner)->post($this->schoolUrl($beta, "/app/marks/{$sheet->id}"), ['rows' => []])->assertNotFound();
        $this->actingAs($betaOwner)->post($this->schoolUrl($beta, "/app/exams/{$exam->id}/calculate"))->assertNotFound();

        // A beta exam cannot use alpha's grading scale.
        $betaSession = $this->inSchool($beta, fn () => $this->academicStructure()['session']);
        $this->actingAs($betaOwner)->post($this->schoolUrl($beta, '/app/exams'), ['name' => 'X', 'academic_session_id' => $betaSession->id, 'grading_scale_id' => $scaleId])
            ->assertSessionHasErrors('grading_scale_id');
    }
}
