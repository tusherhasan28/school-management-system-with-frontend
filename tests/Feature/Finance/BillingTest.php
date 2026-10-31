<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use App\Modules\Finance\Models\FeeDiscount;
use App\Modules\Finance\Models\FeeInvoice;
use App\Modules\Finance\Models\FeeInvoiceItem;
use App\Modules\Finance\Models\StudentFeeAssignment;
use App\Modules\Finance\Services\BillingService;
use App\Modules\Finance\Services\FinanceSettings;
use App\Modules\People\Models\Student;
use Carbon\CarbonImmutable;
use Tests\Concerns\CreatesAcademicStructure;
use Tests\Concerns\CreatesFees;
use Tests\TenantTestCase;

/**
 * Invoice generation (class and opt-in fees, overrides, discounts), re-runs,
 * one-off invoices, waivers/adjustments, late fees and cancellation.
 */
class BillingTest extends TenantTestCase
{
    use CreatesAcademicStructure, CreatesFees;

    /** @var array<string, mixed> */
    private array $s;

    private User $owner;

    /** @var array<string, Student> */
    private array $st;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = $this->academicStructure();
        $this->owner = $this->ownerOf($this->school);
        $c = $this->feeCategories();

        $tuition = $this->feePlan($this->s, $c['tuition'], '1500.00', 'monthly', $this->s['class6']->id);
        $this->feePlan($this->s, $c['exam'], '600.00', 'half_yearly', null, '2026-06');
        $transport = $this->feePlan($this->s, $c['transport'], '800.00', 'monthly', null, '2026-01', 'assigned');

        $this->st = [
            'a' => $this->makeStudent($this->s['session'], $this->s['class6'], $this->s['sectionA'], ['full_name' => 'A'], '01711000001'),
            'b' => $this->makeStudent($this->s['session'], $this->s['class6'], $this->s['sectionA'], ['full_name' => 'B'], '01711000002'),
            'c' => $this->makeStudent($this->s['session'], $this->s['class7'], $this->s['class7A'], ['full_name' => 'C'], '01711000003'),
        ];

        // A: tuition waived. B: takes the bus and has a 10% tuition discount.
        StudentFeeAssignment::create(['student_id' => $this->st['a']->id, 'fee_structure_id' => $tuition->id, 'custom_amount' => '0.00']);
        StudentFeeAssignment::create(['student_id' => $this->st['b']->id, 'fee_structure_id' => $transport->id]);
        FeeDiscount::create(['student_id' => $this->st['b']->id, 'academic_session_id' => $this->s['session']->id, 'fee_category_id' => $c['tuition']->id, 'name' => 'Sibling discount', 'kind' => 'percent', 'value' => '10.00']);
    }

    private function generate(string $period, string $due = null)
    {
        return $this->actingAs($this->owner)->post(route('fees.invoices.generate'), ['period' => $period, 'due_date' => $due ?? $period.'-10']);
    }

    private function invoiceOf(string $key, string $period): ?FeeInvoice
    {
        return FeeInvoice::query()->where('student_id', $this->st[$key]->id)->where('billing_period', $period)->first();
    }

    public function test_generation_applies_class_fees_opt_ins_overrides_and_discounts(): void
    {
        $this->generate('2026-11')->assertSessionHasNoErrors();

        $this->assertNull($this->invoiceOf('a', '2026-11'), 'Tuition waived and no other fee in November.');
        $this->assertNull($this->invoiceOf('c', '2026-11'), 'Class 7 has no monthly fee.');

        $b = $this->invoiceOf('b', '2026-11');
        $this->assertSame(['2150.00', '0.00', 'unpaid'], [$b->total, $b->paid_total, $b->status]);
        $this->assertMatchesRegularExpression('/^INV-\d{4}-\d{5}$/', $b->invoice_no);
        $lines = $b->items->map->only(['description', 'amount', 'discount_amount', 'discount_note', 'line_total'])->all();
        $this->assertSame(['Tuition fee — Nov 2026', '1500.00', '150.00', 'Sibling discount', '1350.00'], array_values($lines[0]));
        $this->assertSame(['Transport fee — Nov 2026', '800.00', '0.00', null, '800.00'], array_values($lines[1]));

        $this->generate('2026-06')->assertSessionHasNoErrors();
        $this->assertSame(['600.00', '2750.00', '600.00'], [$this->invoiceOf('a', '2026-06')->total, $this->invoiceOf('b', '2026-06')->total, $this->invoiceOf('c', '2026-06')->total]);
    }

    public function test_running_generation_again_creates_nothing_new(): void
    {
        $this->generate('2026-06');
        $this->generate('2026-06');
        $counts = app(BillingService::class)->generate('2026-06', null, CarbonImmutable::parse('2026-06-10'), $this->owner);

        $this->assertSame(['created' => 0, 'existing' => 3, 'empty' => 0], $counts);
        $this->assertSame(3, FeeInvoice::query()->count());
    }

    public function test_generation_for_one_class_and_period_validation(): void
    {
        $this->actingAs($this->owner)->post(route('fees.invoices.generate'), ['period' => '2026-06', 'class_id' => $this->s['class7']->id, 'due_date' => '2026-06-10']);
        $this->assertSame([$this->st['c']->id], FeeInvoice::query()->pluck('student_id')->all());

        $this->generate('2027-02')->assertSessionHasErrors('period');
        $this->actingAs($this->owner)->post(route('fees.invoices.generate'), ['period' => 'June', 'due_date' => '2026-06-10'])->assertSessionHasErrors('period');
    }

    public function test_cancelled_invoice_can_be_generated_again(): void
    {
        $this->generate('2026-11');
        $b = $this->invoiceOf('b', '2026-11');

        $this->actingAs($this->owner)->post(route('fees.invoices.cancel', $b), ['reason' => 'x'])->assertSessionHasErrors('reason');
        $this->actingAs($this->owner)->post(route('fees.invoices.cancel', $b), ['reason' => 'Wrong transport route'])->assertSessionHasNoErrors();
        $this->assertSame(['cancelled', 0], [$b->refresh()->status, $b->balance()]);

        $this->generate('2026-11');
        $this->assertSame(2, FeeInvoice::query()->where('student_id', $this->st['b']->id)->count(), 'The cancelled invoice is kept; a new one replaces it.');
    }

    public function test_waivers_and_adjustments_are_new_lines(): void
    {
        $this->generate('2026-11');
        $b = $this->invoiceOf('b', '2026-11');

        $this->actingAs($this->owner)->post(route('fees.invoices.lines', $b), ['kind' => 'waiver', 'description' => 'Flood relief', 'amount' => '3000'])->assertSessionHasErrors('amount');
        $this->actingAs($this->owner)->post(route('fees.invoices.lines', $b), ['kind' => 'waiver', 'description' => 'Flood relief', 'amount' => '150.50'])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('fees.invoices.lines', $b), ['kind' => 'adjustment', 'description' => 'Lost ID card', 'amount' => '100'])->assertSessionHasNoErrors();

        $this->assertSame('2099.50', $b->refresh()->total);
        $this->assertSame(['-150.50', '100.00'], $b->items()->whereIn('kind', ['waiver', 'adjustment'])->pluck('line_total')->all());

        $this->expectException(\LogicException::class);
        FeeInvoiceItem::query()->firstOrFail()->update(['line_total' => '1.00']);
    }

    public function test_one_off_invoice(): void
    {
        $this->actingAs($this->owner)->post(route('fees.students.invoices.store', $this->st['c']), [
            'due_date' => '2026-11-30', 'items' => [['description' => 'ID card', 'amount' => '150'], ['description' => 'Diary', 'amount' => '120.50']],
        ])->assertRedirect();

        $invoice = FeeInvoice::query()->where('student_id', $this->st['c']->id)->sole();
        $this->assertSame(['270.50', null, $this->s['class7A']->id], [$invoice->total, $invoice->billing_period, $invoice->section_id]);
    }

    public function test_late_fee_is_added_once_after_the_grace_period(): void
    {
        app(FinanceSettings::class)->save(['late_fee_enabled' => true, 'late_fee_kind' => 'fixed', 'late_fee_value' => '50.00', 'late_fee_grace_days' => 7]);
        $this->generate('2026-11');
        $billing = app(BillingService::class);
        $b = $this->invoiceOf('b', '2026-11');

        $this->assertSame(0, $billing->applyLateFees(CarbonImmutable::parse('2026-11-17')));
        $this->assertSame(1, $billing->applyLateFees(CarbonImmutable::parse('2026-11-18')));
        $this->assertSame(0, $billing->applyLateFees(CarbonImmutable::parse('2026-12-30')));
        $this->assertSame('2200.00', $b->refresh()->total);

        // Percentage of the balance, and nothing once paid.
        app(FinanceSettings::class)->save(['late_fee_kind' => 'percent', 'late_fee_value' => '2.00']);
        $this->generate('2026-10', '2026-10-10');
        $oct = $this->invoiceOf('b', '2026-10');
        $billing->applyLateFee($oct, CarbonImmutable::parse('2026-11-30'));
        $this->assertSame('2193.00', $oct->refresh()->total); // 2150 + 2% = 43.00

        app(FinanceSettings::class)->save(['late_fee_enabled' => false]);
        $this->assertFalse($billing->applyLateFee($this->invoiceOf('b', '2026-11'), CarbonImmutable::parse('2027-01-01')));
    }

    public function test_billing_permissions(): void
    {
        $this->actingAs($this->userWithRole('Accountant'))->post(route('fees.invoices.generate'), ['period' => '2026-11', 'due_date' => '2026-11-10'])->assertSessionHasNoErrors();
        $this->actingAs($this->userWithRole('Teacher'))->post(route('fees.invoices.generate'), ['period' => '2026-12', 'due_date' => '2026-12-10'])->assertForbidden();
        $this->actingAs($this->userWithRole('Principal'))->post(route('fees.invoices.generate'), ['period' => '2026-12', 'due_date' => '2026-12-10'])->assertForbidden(); // view and refund only
    }
}
