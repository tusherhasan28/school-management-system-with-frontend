<?php

namespace Tests\Feature\Portal;

use App\Models\User;
use App\Modules\Exams\Models\ExamMarkSheet;
use App\Modules\Exams\Models\ExamSubject;
use App\Modules\Exams\Models\Examination;
use App\Modules\Exams\Services\GradingScaleService;
use App\Modules\Finance\Models\FeeInvoice;
use App\Modules\Finance\Gateways\GatewayRegistry;
use App\Modules\Finance\Models\FeePayment;
use App\Modules\Finance\Models\PaymentGatewayAccount;
use App\Modules\Homework\Models\Homework;
use App\Modules\Notices\Models\Notice;
use App\Modules\People\Models\Guardian;
use App\Modules\People\Models\Student;
use App\Modules\Portal\Services\PortalAccounts;
use App\Support\Tenancy\TenantStorage;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAcademicStructure;
use Tests\Concerns\CreatesFees;
use Tests\Concerns\CreatesTeachers;
use Tests\Support\FakeGateway;
use Tests\TenantTestCase;

/**
 * Family portals: a parent sees only their own linked children and a
 * student only themselves, whatever ids are put in the URL.
 */
class PortalAccessTest extends TenantTestCase
{
    use CreatesAcademicStructure, CreatesFees, CreatesTeachers;

    /** @var array<string, mixed> */
    private array $s;

    private User $owner;

    private Student $childA1;

    private Student $childA2;

    private Student $childB;

    private User $parentA;

    private User $parentB;

    private Student $sectionBStudent;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Queue::fake();
        $this->s = $this->academicStructure();
        $this->owner = $this->ownerOf($this->school);

        // Parent A has two children (same guardian phone), parent B one.
        $this->childA1 = $this->makeStudent($this->s['session'], $this->s['class6'], $this->s['sectionA'], ['full_name' => 'Arif A'], '01711000001', '1');
        $this->childA2 = $this->makeStudent($this->s['session'], $this->s['class7'], $this->s['class7A'], ['full_name' => 'Anika A'], '01711000001', '1');
        $this->childB = $this->makeStudent($this->s['session'], $this->s['class6'], $this->s['sectionA'], ['full_name' => 'Bashir B'], '01711000002', '2');

        $this->parentA = $this->parentLogin($this->childA1, 'parent-a@example.com');
        $this->parentB = $this->parentLogin($this->childB, 'parent-b@example.com');
    }

    private function guardianOf(Student $student): Guardian
    {
        return $student->guardians()->firstOrFail();
    }

    private function parentLogin(Student $child, string $email): User
    {
        $guardian = $this->guardianOf($child);
        $guardian->update(['email' => $email]);

        return $this->activate(app(PortalAccounts::class)->inviteGuardian($guardian->fresh()));
    }

    private function studentLogin(Student $student, string $email): User
    {
        $student->update(['email' => $email]);

        return $this->activate(app(PortalAccounts::class)->inviteStudent($student->fresh()));
    }

    private function activate(User $user): User
    {
        $user->forceFill(['status' => 'active', 'email_verified_at' => now()])->save();

        return $user->fresh();
    }

    /** Invoices for every student in Class 6 and 7 (one each). */
    private function invoices(): void
    {
        $tuition = $this->feeCategories()['tuition'];
        $this->feePlan($this->s, $tuition, '1500.00', 'monthly', $this->s['class6']->id);
        $this->feePlan($this->s, $tuition, '1700.00', 'monthly', $this->s['class7']->id);
        $this->actingAs($this->owner)->post(route('fees.invoices.generate'), ['period' => '2026-06', 'due_date' => '2026-06-10'])->assertSessionHasNoErrors();
    }

    private function invoiceOf(Student $student): FeeInvoice
    {
        return FeeInvoice::query()->where('student_id', $student->id)->sole();
    }

    private function collect(Student $student, string $amount): FeePayment
    {
        $this->actingAs($this->owner)->post(route('fees.students.payments.store', $student), [
            'amount' => $amount, 'method' => 'cash', 'idempotency_key' => bin2hex(random_bytes(16)),
        ])->assertSessionHasNoErrors();

        return FeePayment::query()->where('student_id', $student->id)->latest('id')->firstOrFail();
    }

    /** A one-subject Class 6 exam taken by A1 and B, calculated (not yet published). */
    private function calculatedExam(): Examination
    {
        $this->sectionBStudent = $this->makeStudent($this->s['session'], $this->s['class6'], $this->s['sectionB'], ['full_name' => 'Section B kid'], '01711000003', '1');
        $this->subjectFor($this->s['session'], $this->s['class6'], 'Math');
        $scale = app(GradingScaleService::class)->fromPreset('gpa5');
        $this->actingAs($this->owner)->post(route('exams.store'), [
            'name' => 'Half-Yearly', 'academic_session_id' => $this->s['session']->id, 'grading_scale_id' => $scale->id,
            'starts_on' => '2026-06-01', 'ends_on' => '2026-06-15',
        ])->assertSessionHasNoErrors();
        $exam = Examination::query()->latest('id')->firstOrFail();
        $this->actingAs($this->owner)->post(route('exams.subjects.store', $exam), ['class_id' => $this->s['class6']->id, 'template' => 'written_mcq'])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('exams.open', $exam))->assertSessionHasNoErrors();

        $examSubject = ExamSubject::query()->where('examination_id', $exam->id)->sole();
        $sheet = ExamMarkSheet::query()->where('exam_subject_id', $examSubject->id)->where('section_id', $this->s['sectionA']->id)->sole();
        [$written, $mcq] = $examSubject->components()->pluck('id')->all();
        $this->actingAs($this->owner)->post(route('marks.save', $sheet), ['submit' => true, 'rows' => [
            $this->childA1->id => ['status' => 'present', 'parts' => [$written => '60', $mcq => '25']],
            $this->childB->id => ['status' => 'present', 'parts' => [$written => '40', $mcq => '20']],
        ]])->assertSessionHasNoErrors();
        $sheetB = ExamMarkSheet::query()->where('exam_subject_id', $examSubject->id)->where('section_id', $this->s['sectionB']->id)->sole();
        $this->actingAs($this->owner)->post(route('marks.save', $sheetB), ['submit' => true, 'rows' => [
            $this->sectionBStudent->id => ['status' => 'present', 'parts' => [$written => '50', $mcq => '20']],
        ]])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('exams.verify-all', $exam))->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('exams.calculate', $exam))->assertSessionHasNoErrors();

        return $exam->refresh();
    }

    private function publish(Examination $exam): void
    {
        $this->actingAs($this->owner)->post(route('exams.approve', $exam))->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('exams.publish', $exam))->assertSessionHasNoErrors();
    }

    public function test_logins_land_in_their_own_area(): void
    {
        $student = $this->studentLogin($this->childB, 'bashir@example.com');
        [$teacher] = $this->teacher();

        $this->actingAs($this->parentA)->get(route('portal.home'))->assertRedirect(route('parent.dashboard'));
        $this->actingAs($this->parentA)->get(route('parent.dashboard'))->assertRedirect(route('parent.child', $this->childA1->id));
        $this->actingAs($student)->get(route('portal.home'))->assertRedirect(route('student.dashboard'));
        $this->actingAs($teacher)->get(route('portal.home'))->assertRedirect(route('teacher.dashboard'));
        $this->actingAs($this->owner)->get(route('portal.home'))->assertRedirect(route('dashboard'));
    }

    public function test_parent_sees_only_their_linked_children(): void
    {
        $this->actingAs($this->parentA)->get(route('parent.child', $this->childA1->id))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('portal/student')->where('student.id', $this->childA1->id)->where('student.class', 'Class 6')
                ->has('auth.children', 2)->where('auth.children.0.id', $this->childA1->id)->where('auth.children.1.id', $this->childA2->id)->where('auth.area', 'parent'));

        // The child switcher works for both of A's children...
        $this->actingAs($this->parentA)->get(route('parent.tab', [$this->childA2->id, 'attendance']))->assertOk()
            ->assertInertia(fn ($p) => $p->where('student.id', $this->childA2->id)->where('tab', 'attendance'));

        // ...and parent B's child is a 404 on every screen, whatever the tab.
        $this->actingAs($this->parentA)->get(route('parent.child', $this->childB->id))->assertNotFound();
        foreach (['attendance', 'timetable', 'homework', 'results', 'fees', 'notices'] as $tab) {
            $this->actingAs($this->parentA)->get(route('parent.tab', [$this->childB->id, $tab]))->assertNotFound();
        }
        $this->actingAs($this->parentA)->get('/parent/children/999999')->assertNotFound();
        $this->actingAs($this->parentA)->get('/parent/children/abc')->assertNotFound();
        $this->actingAs($this->parentB)->get(route('parent.child', $this->childA1->id))->assertNotFound();
    }

    public function test_unlinking_a_guardian_removes_access_immediately(): void
    {
        $this->actingAs($this->parentA)->get(route('parent.child', $this->childA2->id))->assertOk();
        $this->childA2->guardians()->detach($this->guardianOf($this->childA1)->id);
        $this->actingAs($this->parentA)->get(route('parent.child', $this->childA2->id))->assertNotFound();
    }

    public function test_invoices_and_receipts_are_reachable_only_through_the_own_child(): void
    {
        $this->invoices();
        $paymentB = $this->collect($this->childB, '500.00');
        $paymentA = $this->collect($this->childA1, '1500.00');
        $invoiceA = $this->invoiceOf($this->childA1);
        $invoiceB = $this->invoiceOf($this->childB);

        $this->actingAs($this->parentA)->get(route('parent.tab', [$this->childA1->id, 'fees']))->assertOk()
            ->assertInertia(fn ($p) => $p->has('data.invoices', 1)->where('data.invoices.0.id', $invoiceA->id)->where('data.invoices.0.status', 'paid')
                ->has('data.payments', 1)->where('data.payments.0.id', $paymentA->id)->where('data.totals.due', '0.00')->where('data.gateways', []));
        $this->actingAs($this->parentA)->get(route('parent.invoice', [$this->childA1->id, $invoiceA->id]))->assertOk()
            ->assertInertia(fn ($p) => $p->component('app/fees/invoice-print')->where('invoice.invoice_no', $invoiceA->invoice_no));
        $this->actingAs($this->parentA)->get(route('parent.receipt', [$this->childA1->id, $paymentA->id]))->assertOk()
            ->assertInertia(fn ($p) => $p->component('app/fees/receipt'));

        // Another child's documents: neither under the own child's URL nor under theirs.
        $this->actingAs($this->parentA)->get(route('parent.invoice', [$this->childA1->id, $invoiceB->id]))->assertNotFound();
        $this->actingAs($this->parentA)->get(route('parent.receipt', [$this->childA1->id, $paymentB->id]))->assertNotFound();
        $this->actingAs($this->parentA)->get(route('parent.invoice', [$this->childB->id, $invoiceB->id]))->assertNotFound();
        $this->actingAs($this->parentA)->post(route('parent.pay', [$this->childA1->id, $invoiceB->id]), ['gateway' => 'fakepay'])->assertNotFound();
        $this->assertSame(0, FeePayment::query()->where('method', 'online')->count());
    }

    public function test_only_published_results_are_shown(): void
    {
        $exam = $this->calculatedExam();

        $this->actingAs($this->parentA)->get(route('parent.tab', [$this->childA1->id, 'results']))->assertInertia(fn ($p) => $p->has('data.items', 0));
        $this->actingAs($this->parentA)->get(route('parent.report-card', [$this->childA1->id, $exam->id]))->assertNotFound();

        $this->publish($exam);

        $this->actingAs($this->parentA)->get(route('parent.tab', [$this->childA1->id, 'results']))
            ->assertInertia(fn ($p) => $p->has('data.items', 1)->where('data.items.0.exam_id', $exam->id)->where('data.items.0.section_position', 1));
        $this->actingAs($this->parentA)->get(route('parent.report-card', [$this->childA1->id, $exam->id]))->assertOk()
            ->assertInertia(fn ($p) => $p->component('app/exams/report-cards')->has('cards', 1)->where('cards.0.student.id', $this->childA1->id));

        // B's card is not reachable by A; A2 did not sit this exam.
        $this->actingAs($this->parentA)->get(route('parent.report-card', [$this->childB->id, $exam->id]))->assertNotFound();
        $this->actingAs($this->parentA)->get(route('parent.report-card', [$this->childA2->id, $exam->id]))->assertNotFound();
    }

    public function test_student_sees_only_their_own_records(): void
    {
        $this->invoices();
        $exam = $this->calculatedExam();
        $this->publish($exam);
        $student = $this->studentLogin($this->childB, 'bashir@example.com');

        $this->actingAs($student)->get(route('student.dashboard'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('portal/student')->where('student.id', $this->childB->id)->where('base', '/student')->where('auth.area', 'student')->has('auth.children', 0));
        $this->actingAs($student)->get(route('student.report-card', $exam->id))->assertOk()
            ->assertInertia(fn ($p) => $p->where('cards.0.student.id', $this->childB->id));
        $this->actingAs($student)->get(route('student.invoice', $this->invoiceOf($this->childB)->id))->assertOk();
        $this->actingAs($student)->get(route('student.invoice', $this->invoiceOf($this->childA1)->id))->assertNotFound();

        // No route takes a student id: the parent URL shape is closed to students.
        $this->actingAs($student)->get(route('parent.child', $this->childA1->id))->assertForbidden();
        $this->actingAs($student)->get(route('parent.child', $this->childB->id))->assertForbidden();
    }

    public function test_family_logins_cannot_enter_the_staff_area_or_teacher_dashboard(): void
    {
        foreach (['/app/dashboard', '/app/students', '/app/students/'.$this->childA1->id, '/app/fees'] as $path) {
            $this->actingAs($this->parentA)->get($path)->assertRedirect(route('parent.dashboard'));
        }
        $this->actingAs($this->parentA)->getJson('/app/students')->assertForbidden();
        $this->actingAs($this->parentA)->get(route('teacher.dashboard'))->assertForbidden();
        $this->actingAs($this->parentA)->get(route('student.dashboard'))->assertForbidden();
        // Account settings stay available.
        $this->actingAs($this->parentA)->get(route('profile.edit'))->assertOk();
    }

    public function test_disabled_portal_login_is_signed_out(): void
    {
        app(PortalAccounts::class)->disable($this->guardianOf($this->childA1));
        $this->assertSame('disabled', $this->parentA->fresh()->status);
        $this->actingAs($this->parentA->fresh())->get(route('parent.child', $this->childA1->id))->assertRedirect(route('login'));
    }

    public function test_homework_and_notice_attachments_follow_visibility(): void
    {
        Storage::fake('local');
        $storage = app(TenantStorage::class);
        $path = $storage->path('homework/sheet.pdf');
        $storage->disk()->put($path, '%PDF-1.4 test');

        $mine = Homework::create(['academic_session_id' => $this->s['session']->id, 'class_id' => $this->s['class6']->id, 'section_id' => $this->s['sectionA']->id,
            'subject_id' => $this->subjectFor($this->s['session'], $this->s['class6'], 'Math')->id, 'title' => 'Fractions', 'assigned_on' => '2026-06-01', 'status' => 'published']);
        $mine->forceFill(['attachment_path' => $path, 'attachment_name' => 'sheet.pdf'])->save();
        $draft = Homework::create(['academic_session_id' => $this->s['session']->id, 'class_id' => $this->s['class6']->id, 'section_id' => null,
            'subject_id' => $mine->subject_id, 'title' => 'Draft', 'assigned_on' => '2026-06-01', 'status' => 'draft']);
        $draft->forceFill(['attachment_path' => $path, 'attachment_name' => 'draft.pdf'])->save();

        $this->actingAs($this->parentA)->get(route('parent.tab', [$this->childA1->id, 'homework']))
            ->assertInertia(fn ($p) => $p->has('data.items', 1)->where('data.items.0.title', 'Fractions'));
        $this->actingAs($this->parentA)->get(route('parent.homework.attachment', [$this->childA1->id, $mine->id]))->assertOk();
        // Class 7 child: homework of Class 6 is not theirs; drafts are nobody's.
        $this->actingAs($this->parentA)->get(route('parent.homework.attachment', [$this->childA2->id, $mine->id]))->assertNotFound();
        $this->actingAs($this->parentA)->get(route('parent.homework.attachment', [$this->childA1->id, $draft->id]))->assertNotFound();

        $staffOnly = Notice::create(['title' => 'Staff meeting', 'body' => 'Room 2', 'audiences' => ['staff'], 'status' => 'published']);
        $staffOnly->forceFill(['attachment_path' => $path, 'attachment_name' => 'agenda.pdf'])->save();
        $forParents = Notice::create(['title' => 'PTA meeting', 'body' => '**Saturday**', 'audiences' => ['parents'], 'status' => 'published']);
        $forParents->forceFill(['attachment_path' => $path, 'attachment_name' => 'pta.pdf'])->save();

        $this->actingAs($this->parentA)->get(route('parent.tab', [$this->childA1->id, 'notices']))
            ->assertInertia(fn ($p) => $p->has('data.items', 1)->where('data.items.0.title', 'PTA meeting')->where('data.items.0.body', "<p><strong>Saturday</strong></p>\n"));
        $this->actingAs($this->parentA)->get(route('parent.notices.attachment', $forParents->id))->assertOk();
        $this->actingAs($this->parentA)->get(route('parent.notices.attachment', $staffOnly->id))->assertNotFound();
    }

    public function test_other_schools_students_are_unreachable(): void
    {
        $beta = $this->provisionSchool('beta');
        $betaStudent = $this->inSchool($beta, function () {
            $s = $this->academicStructure();

            return $this->makeStudent($s['session'], $s['class6'], $s['sectionA'], ['full_name' => 'Beta kid'], '01711000001');
        });
        $this->onSchool($this->school);

        $this->actingAs($this->parentA)->get(route('parent.child', $betaStudent->id))->assertNotFound();

        // A parent session never carries over to another school's host.
        $this->actingAs($this->parentA)->get($this->schoolUrl($beta, '/parent/dashboard'))->assertRedirect();
        $this->assertGuest();
    }

    public function test_staff_invite_and_disable_portal_logins(): void
    {
        $guardian = $this->guardianOf($this->childB);
        $this->childB->update(['email' => null]);

        // Without an email there is nothing to send the invitation to.
        $this->actingAs($this->owner)->post(route('students.portal.store', $this->childB))->assertSessionHasErrors('email');
        $this->actingAs($this->owner)->put(route('students.update', $this->childB), [
            'full_name' => 'Bashir B', 'gender' => 'male', 'email' => 'bashir@example.com',
        ]);
        $this->assertSame('bashir@example.com', $this->childB->fresh()->email);
        $this->actingAs($this->owner)->post(route('students.portal.store', $this->childB))->assertSessionHasNoErrors();
        $login = User::query()->where('email', 'bashir@example.com')->sole();
        $this->assertTrue($login->hasRole('Student'));
        $this->assertSame([$login->id, 'invited'], [$this->childB->fresh()->user_id, $login->status]);

        $this->actingAs($this->owner)->get(route('students.show', $this->childB))->assertInertia(fn ($p) => $p->where('portal.status', 'invited'));
        $this->actingAs($this->owner)->get(route('guardians.show', $guardian))->assertInertia(fn ($p) => $p->where('portal.status', 'active'));

        $this->actingAs($this->owner)->delete(route('guardians.portal.destroy', $guardian))->assertSessionHasNoErrors();
        $this->assertSame('disabled', $this->parentB->fresh()->status);
        $this->actingAs($this->owner)->post(route('guardians.portal.store', $guardian))->assertSessionHasNoErrors();
        $this->assertSame('active', $this->parentB->fresh()->status);

        // Only users.manage may do this.
        $officer = $this->userWithRole('Admission Officer');
        $this->actingAs($officer)->post(route('guardians.portal.store', $guardian))->assertForbidden();
        $this->actingAs($officer)->get(route('guardians.show', $guardian))->assertInertia(fn ($p) => $p->where('portal', null));
    }

    public function test_a_teacher_who_is_also_a_parent_keeps_one_login(): void
    {
        [$teacher] = $this->teacher();
        $teacher->update(['email' => 'teacher-parent@example.com']);
        $kid = $this->makeStudent($this->s['session'], $this->s['class6'], $this->s['sectionB'], ['full_name' => 'Teacher kid'], '01711000009');
        $guardian = $this->guardianOf($kid);
        $guardian->update(['email' => 'Teacher-Parent@example.com']);

        app(PortalAccounts::class)->inviteGuardian($guardian->fresh());

        $this->assertSame(1, User::query()->where('email', 'teacher-parent@example.com')->count());
        $this->assertTrue($teacher->fresh()->hasRole('Parent'));
        $this->actingAs($teacher->fresh())->get(route('portal.home'))->assertRedirect(route('teacher.dashboard'));
        $this->actingAs($teacher->fresh())->get(route('parent.child', $kid->id))->assertOk();
        $this->actingAs($teacher->fresh())->get(route('parent.child', $this->childA1->id))->assertNotFound();

        // Turning parent access off keeps the staff login working.
        app(PortalAccounts::class)->disable($guardian->fresh());
        $this->assertSame('active', $teacher->fresh()->status);
        $this->assertFalse($teacher->fresh()->hasRole('Parent'));
        $this->actingAs($teacher->fresh())->get(route('parent.child', $kid->id))->assertForbidden();
    }

    public function test_parent_can_start_an_online_payment_but_it_is_not_paid_until_verified(): void
    {
        $this->invoices();
        app(GatewayRegistry::class)->register(new FakeGateway);
        PaymentGatewayAccount::create(['gateway' => 'fakepay', 'mode' => 'sandbox', 'is_enabled' => true, 'credentials' => ['merchant_id' => 'M1', 'secret' => 'test-secret']]);
        $invoice = $this->invoiceOf($this->childA1);

        $this->actingAs($this->parentA)->get(route('parent.tab', [$this->childA1->id, 'fees']))
            ->assertInertia(fn ($p) => $p->where('data.gateways', ['fakepay' => 'FakePay']));
        $this->actingAs($this->parentA)->post(route('parent.pay', [$this->childA1->id, $invoice->id]), ['gateway' => 'nope'])->assertSessionHasErrors('gateway');

        $this->actingAs($this->parentA)->post(route('parent.pay', [$this->childA1->id, $invoice->id]), ['gateway' => 'fakepay'], ['X-Inertia' => 'true'])
            ->assertStatus(409)->assertHeader('X-Inertia-Location', route('portal.checkout'));
        $payment = FeePayment::query()->where('method', 'online')->sole();
        $this->assertSame(['pending', $this->childA1->id], [$payment->status, $payment->student_id]);
        $this->assertSame('unpaid', $invoice->fresh()->status);

        $this->actingAs($this->parentA)->get(route('portal.checkout'))->assertOk()->assertSee('https://fakepay.test/checkout', false)->assertSee($payment->public_id);
        $this->actingAs($this->parentA)->get(route('portal.checkout'))->assertNotFound(); // one-time
    }
}
