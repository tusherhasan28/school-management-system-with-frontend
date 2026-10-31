<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use App\Modules\Finance\Models\FeeInvoice;
use App\Modules\Finance\Models\FeePayment;
use App\Modules\People\Models\Student;
use Carbon\Carbon;
use Tests\Concerns\CreatesAcademicStructure;
use Tests\Concerns\CreatesFees;
use Tests\TenantTestCase;

/** The finance screens render the right numbers for the right people. */
class FinanceScreensTest extends TenantTestCase
{
    use CreatesAcademicStructure, CreatesFees;

    private User $accountant;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-20 10:00:00');
        $s = $this->academicStructure();
        $this->feePlan($s, $this->feeCategories()['tuition'], '1500.00', 'monthly', $s['class6']->id);
        $this->student = $this->makeStudent($s['session'], $s['class6'], $s['sectionA'], ['full_name' => 'Rina Akter']);
        $this->accountant = $this->userWithRole('Accountant');

        foreach (['2026-06', '2026-07'] as $p) {
            $this->actingAs($this->accountant)->post(route('fees.invoices.generate'), ['period' => $p, 'due_date' => $p.'-10']);
        }

        $this->actingAs($this->accountant)->post(route('fees.students.payments.store', $this->student), [
            'amount' => '2000', 'method' => 'mobile_banking', 'reference' => 'BKX123', 'idempotency_key' => str_repeat('a', 32),
        ])->assertSessionHasNoErrors();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_dashboard_and_lists(): void
    {
        $this->actingAs($this->accountant)->get(route('fees.dashboard', ['q' => 'Rina']))->assertOk()->assertInertia(fn ($p) => $p
            ->where('stats.today', '2000.00')->where('stats.outstanding', '1000.00')->where('stats.overdue', '1000.00')->where('stats.overdue_count', 1)
            ->has('matches', 1)->has('recent', 1));

        $this->actingAs($this->accountant)->get(route('fees.invoices.index', ['status' => 'overdue']))->assertOk()
            ->assertInertia(fn ($p) => $p->has('invoices.data', 1)->where('invoices.data.0.billing_period', '2026-07')->where('invoices.data.0.overdue', true));

        $this->actingAs($this->accountant)->get(route('fees.payments.index'))->assertOk()
            ->assertInertia(fn ($p) => $p->has('payments.data', 1)->where('payments.data.0.reference', 'BKX123')->where('can.refund', false));
    }

    public function test_student_account_invoice_receipt_and_print(): void
    {
        $this->actingAs($this->accountant)->get(route('fees.students.show', $this->student))->assertOk()->assertInertia(fn ($p) => $p
            ->where('summary.due', '1000.00')->where('summary.overdue', '1000.00')->where('summary.paid_this_session', '2000.00')
            ->has('invoices', 2)->has('openInvoices', 1)->has('payments', 1));

        $invoice = FeeInvoice::query()->where('billing_period', '2026-07')->sole();
        $this->actingAs($this->accountant)->get(route('fees.invoices.show', $invoice))->assertOk()
            ->assertInertia(fn ($p) => $p->where('invoice.balance', '1000.00')->has('invoice.items', 1)->has('invoice.payments', 1)->where('invoice.payments.0.allocated', '500.00'));
        $this->actingAs($this->accountant)->get(route('fees.invoices.print', $invoice))->assertOk();

        $payment = FeePayment::query()->sole();
        $this->actingAs($this->accountant)->get(route('fees.payments.receipt', $payment))->assertOk()
            ->assertInertia(fn ($p) => $p->where('payment.amount', '2000.00')->has('payment.allocations', 2));

        $this->actingAs($this->accountant)->get(route('students.show', $this->student))->assertInertia(fn ($p) => $p->where('fees.due', '1000.00')->where('fees.open', 1));
    }

    public function test_reports_and_export(): void
    {
        $this->actingAs($this->accountant)->get(route('fees.reports', ['from' => '2026-07-01', 'to' => '2026-07-31']))->assertOk()->assertInertia(fn ($p) => $p
            ->where('collections.net', '2000.00')->where('collections.by_method.0.method', 'mobile_banking')
            ->where('dues.totals.total', '1000.00')->where('dues.totals.d1_30', '1000.00')->where('dues.rows.0.students', 1));

        $this->actingAs($this->accountant)->get(route('fees.reports.export', ['report' => 'collections', 'from' => '2026-07-01', 'to' => '2026-07-31']))
            ->assertOk()->assertDownload('collections-2026-07-01-2026-07-31.xlsx');
        $this->actingAs($this->accountant)->get(route('fees.reports.export', ['report' => 'dues']))->assertOk();
        $this->actingAs($this->accountant)->get(route('fees.reports.export', ['report' => 'secrets']))->assertNotFound();
    }

    public function test_access(): void
    {
        $teacher = $this->userWithRole('Teacher');

        foreach ([route('fees.dashboard'), route('fees.invoices.index'), route('fees.payments.index'), route('fees.reports'), route('fees.setup'), route('fees.students.show', $this->student)] as $url) {
            $this->actingAs($teacher)->get($url)->assertForbidden();
        }

        $this->actingAs($this->userWithRole('Principal'))->get(route('fees.setup'))->assertForbidden(); // principal: view, refund, reports
        $this->actingAs($this->userWithRole('Principal'))->get(route('fees.reports'))->assertOk();
        $this->actingAs($this->accountant)->get(route('fees.setup'))->assertOk()->assertInertia(fn ($p) => $p->has('gateways', 0)->has('structures', 1));
    }
}
