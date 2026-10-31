<?php

namespace Tests\Feature\Finance;

use App\Models\ActivityLog;
use App\Models\User;
use App\Modules\Finance\Models\FeeInvoice;
use App\Modules\Finance\Models\FeePayment;
use App\Modules\Finance\Models\FeePaymentAllocation;
use App\Modules\Finance\Services\InvoiceLedger;
use App\Modules\People\Models\Student;
use App\Support\Money\Money;
use Tests\Concerns\CreatesAcademicStructure;
use Tests\Concerns\CreatesFees;
use Tests\TenantTestCase;

/**
 * Manual collection, allocation, partial payments, double submission,
 * reversals/refunds and the invariant that cached totals match the ledger.
 */
class PaymentTest extends TenantTestCase
{
    use CreatesAcademicStructure, CreatesFees;

    /** @var array<string, mixed> */
    private array $s;

    private User $owner;

    private Student $student;

    private FeeInvoice $june;

    private FeeInvoice $july;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = $this->academicStructure();
        $this->owner = $this->ownerOf($this->school);
        $c = $this->feeCategories();
        $this->feePlan($this->s, $c['tuition'], '1500.00', 'monthly', $this->s['class6']->id);
        $this->student = $this->makeStudent($this->s['session'], $this->s['class6'], $this->s['sectionA'], ['full_name' => 'Payer']);

        foreach (['2026-06', '2026-07'] as $p) {
            $this->actingAs($this->owner)->post(route('fees.invoices.generate'), ['period' => $p, 'due_date' => $p.'-10']);
        }

        $this->june = FeeInvoice::query()->where('billing_period', '2026-06')->sole();
        $this->july = FeeInvoice::query()->where('billing_period', '2026-07')->sole();
    }

    private function pay(array $data, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)->post(route('fees.students.payments.store', $this->student), $data + [
            'method' => 'cash', 'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        ]);
    }

    private function assertLedgerMatchesCache(): void
    {
        $ledger = app(InvoiceLedger::class);

        foreach (FeeInvoice::query()->get() as $i) {
            $this->assertSame(Money::format($ledger->total($i)), $i->total, "total of {$i->invoice_no}");
            $this->assertSame(Money::format($ledger->paid($i)), $i->paid_total, "paid of {$i->invoice_no}");
        }
    }

    public function test_partial_payment_settles_the_oldest_invoice_first(): void
    {
        $this->pay(['amount' => '2000'])->assertSessionHasNoErrors();

        $this->assertSame(['paid', '1500.00'], [$this->june->refresh()->status, $this->june->paid_total]);
        $this->assertSame(['partially_paid', '500.00', 1000_00], [$this->july->refresh()->status, $this->july->paid_total, $this->july->balance()]);

        $payment = FeePayment::query()->sole();
        $this->assertMatchesRegularExpression('/^RCP-\d{4}-\d{6}$/', $payment->receipt_no);
        $this->assertSame(['1500.00', '500.00'], $payment->allocations()->orderBy('fee_invoice_id')->pluck('amount')->all());
        $this->assertLedgerMatchesCache();
    }

    public function test_explicit_allocation_and_its_validation(): void
    {
        $this->pay(['amount' => '700', 'allocations' => [$this->july->id => '700']])->assertSessionHasNoErrors();
        $this->assertSame(['unpaid', 'partially_paid'], [$this->june->refresh()->status, $this->july->refresh()->status]);

        $this->pay(['amount' => '900', 'allocations' => [$this->july->id => '900']])->assertSessionHasErrors("allocations.{$this->july->id}"); // only 800 left
        $this->pay(['amount' => '500', 'allocations' => [$this->june->id => '400']])->assertSessionHasErrors('allocations');          // does not add up
        $this->assertSame(1, FeePayment::query()->count());
    }

    public function test_payment_cannot_exceed_what_is_owed(): void
    {
        $this->pay(['amount' => '3000.01'])->assertSessionHasErrors('amount');
        $this->pay(['amount' => '0'])->assertSessionHasErrors('amount');
        $this->pay(['amount' => '10.555'])->assertSessionHasErrors('amount');
        $this->pay(['amount' => '3000', 'method' => 'bank'])->assertSessionHasErrors('reference'); // bank needs a reference
        $this->assertSame(0, FeePayment::query()->count());
    }

    public function test_submitting_the_same_form_twice_records_one_payment(): void
    {
        $data = ['amount' => '1500', 'method' => 'cash', 'idempotency_key' => 'form-key-0000000001'];

        $this->actingAs($this->owner)->post(route('fees.students.payments.store', $this->student), $data)->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('fees.students.payments.store', $this->student), $data)->assertSessionHasNoErrors();

        $this->assertSame(1, FeePayment::query()->count());
        $this->assertSame('1500.00', $this->june->refresh()->paid_total);
    }

    public function test_reversal_restores_the_balance_and_keeps_the_payment(): void
    {
        $this->pay(['amount' => '2000']);
        $payment = FeePayment::query()->sole();

        $this->actingAs($this->owner)->post(route('fees.payments.reverse', $payment), ['type' => 'reversal', 'reason' => 'no'])->assertSessionHasErrors('reason');
        $this->actingAs($this->owner)->post(route('fees.payments.reverse', $payment), ['type' => 'refund', 'reason' => 'Student left school'])->assertSessionHasErrors('refund_method');
        $this->actingAs($this->owner)->post(route('fees.payments.reverse', $payment), ['type' => 'reversal', 'reason' => 'Entered on the wrong student'])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('fees.payments.reverse', $payment), ['type' => 'reversal', 'reason' => 'Entered on the wrong student'])->assertSessionHasErrors('payment');

        $this->assertSame(['unpaid', '0.00'], [$this->june->refresh()->status, $this->june->paid_total]);
        $this->assertSame(['successful', '2000.00', 2], [$payment->refresh()->status, $payment->amount, $payment->allocations()->count()]);
        $this->assertTrue(ActivityLog::query()->where('action', 'finance.payment_reversal')->exists());
        $this->assertLedgerMatchesCache();

        // Now the invoice has no live payments and may be cancelled.
        $this->actingAs($this->owner)->post(route('fees.invoices.cancel', $this->june), ['reason' => 'Duplicate invoice'])->assertSessionHasNoErrors();
    }

    public function test_invoice_with_payments_cannot_be_cancelled_and_records_are_immutable(): void
    {
        $this->pay(['amount' => '100']);
        $this->actingAs($this->owner)->post(route('fees.invoices.cancel', $this->june), ['reason' => 'Changed my mind'])->assertSessionHasErrors('invoice');

        $payment = FeePayment::query()->sole();
        $this->assertThrows(fn () => $payment->update(['amount' => '1.00']), \LogicException::class);
        $this->assertThrows(fn () => $payment->delete(), \LogicException::class);
        $this->assertThrows(fn () => FeePaymentAllocation::query()->first()->delete(), \LogicException::class);
        $this->assertThrows(fn () => $this->june->delete(), \LogicException::class);
    }

    public function test_roles(): void
    {
        $accountant = $this->userWithRole('Accountant');
        $principal = $this->userWithRole('Principal');

        $this->pay(['amount' => '100'], $accountant)->assertSessionHasNoErrors();
        $payment = FeePayment::query()->sole();
        $this->actingAs($accountant)->post(route('fees.payments.reverse', $payment), ['type' => 'reversal', 'reason' => 'Cashier mistake'])->assertForbidden();
        $this->pay(['amount' => '100'], $principal)->assertForbidden();
        $this->actingAs($principal)->post(route('fees.payments.reverse', $payment), ['type' => 'reversal', 'reason' => 'Cashier mistake'])->assertSessionHasNoErrors();
        $this->pay(['amount' => '100'], $this->userWithRole('Teacher'))->assertForbidden();
    }

    public function test_other_schools_cannot_touch_invoices_or_payments(): void
    {
        $this->pay(['amount' => '100']);
        $payment = FeePayment::query()->sole();

        $beta = $this->provisionSchool('beta');
        $this->flushSession();
        $betaOwner = $this->ownerOf($beta);

        foreach (["/app/fees/invoices/{$this->june->id}", "/app/fees/students/{$this->student->id}", "/app/fees/payments/{$payment->id}/receipt", "/app/fees/invoices/{$this->june->id}/print"] as $path) {
            $this->actingAs($betaOwner)->get($this->schoolUrl($beta, $path))->assertNotFound();
        }

        $this->actingAs($betaOwner)->post($this->schoolUrl($beta, "/app/fees/payments/{$payment->id}/reverse"), ['type' => 'reversal', 'reason' => 'Not ours at all'])->assertNotFound();
        $this->actingAs($betaOwner)->post($this->schoolUrl($beta, "/app/fees/students/{$this->student->id}/payments"), ['amount' => '1', 'method' => 'cash', 'idempotency_key' => str_repeat('x', 20)])->assertNotFound();
        $this->assertSame('100.00', $this->june->refresh()->paid_total);
    }
}
