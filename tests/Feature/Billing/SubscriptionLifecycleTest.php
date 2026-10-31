<?php

namespace Tests\Feature\Billing;

use App\Enums\SchoolStatus;
use App\Models\PlatformActivityLog;
use App\Modules\Billing\Models\PlatformInvoice;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Services\SubscriptionService;
use App\Modules\People\Models\Student;
use Carbon\CarbonImmutable;
use Tests\Concerns\CreatesAcademicStructure;
use Tests\Concerns\CreatesPlans;
use Tests\TenantTestCase;

/**
 * Trial, renewal invoices, grace, suspension, payment, cancellation and
 * expiry — and that none of it deletes school data.
 */
class SubscriptionLifecycleTest extends TenantTestCase
{
    use CreatesAcademicStructure, CreatesPlans;

    private SubscriptionService $billing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-01-01 10:00:00', 'UTC'));
        $this->billing = app(SubscriptionService::class);
    }

    private function tickAt(string $when): array
    {
        $this->travelTo(CarbonImmutable::parse($when, 'UTC'));

        return $this->billing->tick();
    }

    private function pay(PlatformInvoice $invoice): void
    {
        $this->billing->recordPayment($invoice, ['amount' => $invoice->total, 'method' => 'bank', 'transaction_id' => 'TX-'.$invoice->id]);
    }

    public function test_trial_then_paid_renewal_keeps_the_school_active(): void
    {
        $newSchool = $this->provisionSchool('newbie', SchoolStatus::Pending); // approved registration, not yet live
        $sub = $this->billing->assign($newSchool, $this->plan(), 'monthly', trial: true);
        $this->assertSame(['trialing', '2026-01-15'], [$sub->status, $sub->current_period_end->toDateString()]);
        $this->assertSame(SchoolStatus::Trial, $newSchool->fresh()->status);
        $this->assertSame(0, PlatformInvoice::query()->count());

        // Seven days before the trial ends, the first invoice is issued (due when the trial ends); re-runs add nothing.
        $this->assertSame(1, $this->tickAt('2026-01-08 11:00')['invoiced']);
        $this->assertSame(0, $this->tickAt('2026-01-09 03:00')['invoiced']);
        $invoice = PlatformInvoice::query()->sole();
        $this->assertSame(['2000.00', '2026-01-15', 'unpaid'], [$invoice->total, $invoice->due_at->toDateString(), $invoice->status]);
        $this->assertMatchesRegularExpression('/^PINV-2026-00001$/', $invoice->invoice_number);

        $this->pay($invoice);
        $this->tickAt('2026-01-15 11:00');
        $sub->refresh();
        $this->assertSame(['active', '2026-02-15'], [$sub->status, $sub->current_period_end->toDateString()]);
        $this->assertSame(SchoolStatus::Active, $newSchool->fresh()->status);
    }

    public function test_unpaid_invoice_leads_to_grace_then_suspension_and_payment_reactivates(): void
    {
        $s = $this->academicStructure();
        $this->makeStudent($s['session'], $s['class6'], $s['sectionA'], ['full_name' => 'Kept Student']);
        $sub = $this->billing->assign($this->school, $this->plan(), 'monthly');
        $invoice = PlatformInvoice::query()->sole(); // first period invoiced at once, due now
        $this->assertSame('active', $sub->status);

        $this->tickAt('2026-01-02 03:00');
        $sub->refresh();
        $this->assertSame(['past_due', '2026-01-08'], [$sub->status, $sub->grace_ends_at->toDateString()]);
        $this->assertSame(SchoolStatus::Active, $this->school->fresh()->status); // still usable during grace

        $this->assertSame(0, $this->tickAt('2026-01-07 03:00')['suspended']);
        $this->assertSame(1, $this->tickAt('2026-01-08 11:00')['suspended']);
        $this->assertSame('suspended', $sub->fresh()->status);
        $school = $this->school->fresh();
        $this->assertSame(SchoolStatus::Suspended, $school->status);
        $this->get('/login')->assertStatus(503); // school blocked…
        $this->assertSame(1, $this->inSchool($school, fn () => Student::query()->count())); // …data kept

        $this->travelTo(CarbonImmutable::parse('2026-03-10 09:00', 'UTC'));
        $this->pay($invoice);
        $sub->refresh();
        $this->assertSame(['active', null], [$sub->status, $sub->grace_ends_at]);
        // Service restarts from the payment date; the suspended weeks are not billed again.
        $this->assertSame(['2026-03-10', '2026-04-10'], [$sub->current_period_start->toDateString(), $sub->current_period_end->toDateString()]);
        $this->assertSame(SchoolStatus::Active, $this->school->fresh()->status);
        $this->assertTrue(PlatformActivityLog::query()->where('action', 'subscription.suspended')->exists());
    }

    public function test_payment_must_match_and_cannot_be_recorded_twice(): void
    {
        $this->billing->assign($this->school, $this->plan(), 'yearly');
        $invoice = PlatformInvoice::query()->sole();
        $this->assertSame('20000.00', $invoice->total);

        try {
            $this->billing->recordPayment($invoice, ['amount' => '100.00', 'method' => 'bank']);
            $this->fail('partial payment accepted');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }

        $this->pay($invoice);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->pay($invoice->fresh());
    }

    public function test_cancel_at_period_end_and_expiry_without_renewal(): void
    {
        $sub = $this->billing->assign($this->school, $this->plan(), 'monthly');
        $this->pay(PlatformInvoice::query()->sole());
        $this->billing->cancel($sub, atPeriodEnd: true);

        $this->assertSame(0, $this->tickAt('2026-01-25 03:00')['invoiced']); // no renewal invoice for a cancelling subscription
        $this->assertSame(SchoolStatus::Active, $this->school->fresh()->status);
        $this->tickAt('2026-02-01 11:00');
        $this->assertSame('cancelled', $sub->fresh()->status);
        $this->assertSame(SchoolStatus::Cancelled, $this->school->fresh()->status);

        // A new subscription brings the school back.
        $next = $this->billing->assign($this->school->fresh(), $this->plan('pro'), 'monthly', autoRenew: false);
        $this->assertSame(SchoolStatus::Active, $this->school->fresh()->status);
        $this->pay(PlatformInvoice::query()->where('subscription_id', $next->id)->sole());
        $this->tickAt('2026-03-02 03:00');
        $this->assertSame('expired', $next->fresh()->status);
        $this->assertSame(SchoolStatus::Suspended, $this->school->fresh()->status);
    }

    public function test_assigning_a_new_plan_replaces_the_old_subscription_and_voids_its_unpaid_invoices(): void
    {
        $old = $this->billing->assign($this->school, $this->plan(), 'monthly');
        $this->billing->assign($this->school, $this->plan('pro', [], ['price_monthly' => '5000.00']), 'monthly');

        $this->assertSame('cancelled', $old->fresh()->status);
        $this->assertSame(1, Subscription::query()->live()->count());
        $this->assertSame(['void', 'unpaid'], PlatformInvoice::query()->orderBy('id')->pluck('status')->all());
        $this->assertSame('5000.00', PlatformInvoice::query()->latest('id')->first()->total);
    }

    public function test_platform_suspension_for_other_reasons_is_not_undone_by_billing(): void
    {
        $sub = $this->billing->assign($this->school, $this->plan(), 'monthly');
        app(\App\Services\Tenancy\SchoolLifecycle::class)->transition($this->school->fresh(), SchoolStatus::Suspended, 'abuse report');
        $this->pay(PlatformInvoice::query()->sole());

        $this->assertSame('active', $sub->fresh()->status);
        $this->assertSame(SchoolStatus::Suspended, $this->school->fresh()->status);
    }

    public function test_daily_command_runs(): void
    {
        $this->billing->assign($this->school, $this->plan(), 'monthly', trial: true);
        $this->travelTo(CarbonImmutable::parse('2026-01-09 03:00', 'UTC'));
        $this->artisan('billing:run')->expectsOutputToContain('invoiced')->assertSuccessful();
        $this->assertSame(1, PlatformInvoice::query()->count());
    }
}
