<?php

namespace Tests\Feature\Billing;

use App\Enums\PlatformRole;
use App\Enums\SchoolStatus;
use App\Models\PlatformUser;
use App\Models\School;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\PlatformInvoice;
use App\Modules\Billing\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPlans;
use Tests\TestCase;

/**
 * Acceptance: a platform administrator creates a plan, puts a school on it,
 * suspends and reactivates it and sees the billing history; the school sees
 * (only) its own plan and invoices.
 */
class PlatformBillingScreensTest extends TestCase
{
    use CreatesPlans, RefreshDatabase;

    private School $school;

    private PlatformUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->provisionSchool('alpha');
        $this->admin = PlatformUser::factory()->create();
    }

    private function platform(string $path): string
    {
        return $this->platformUrl('/platform'.$path);
    }

    private function features(array $o = []): array
    {
        return $o + ['students' => 300, 'staff' => '', 'sms_per_month' => 500, 'storage_mb' => 2048, 'website' => true, 'parent_portal' => true,
            'student_portal' => false, 'online_payments' => false, 'sms' => true, 'bulk_import' => true];
    }

    public function test_administrator_runs_the_billing_flow_end_to_end(): void
    {
        $this->actingAs($this->admin, 'platform')->post($this->platform('/plans'), [
            'code' => 'standard', 'name' => 'Standard', 'name_bn' => 'স্ট্যান্ডার্ড', 'price_monthly' => '3000', 'price_yearly' => '30000',
            'trial_days' => 30, 'grace_days' => 10, 'is_active' => true, 'is_public' => true, 'features' => $this->features(),
        ])->assertSessionHasNoErrors();
        $plan = Plan::query()->where('code', 'standard')->sole();
        $this->assertSame(['students' => 300, 'staff' => null, 'student_portal' => false], array_intersect_key($plan->load('features')->featureValues(), array_flip(['students', 'staff', 'student_portal'])));

        $this->actingAs($this->admin, 'platform')->get($this->platform('/plans'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('platform/plans/index')->has('plans', 1)->has('catalogue', 10));

        $this->actingAs($this->admin, 'platform')->post($this->platform("/schools/{$this->school->id}/billing/assign"), ['plan_id' => $plan->id, 'billing_cycle' => 'monthly'])
            ->assertSessionHasNoErrors();
        $invoice = PlatformInvoice::query()->sole();
        $this->assertSame('3000.00', $invoice->total);

        $this->actingAs($this->admin, 'platform')->post($this->platform("/schools/{$this->school->id}/billing/suspend"), ['reason' => 'Asked by school'])->assertSessionHasNoErrors();
        $this->assertSame(SchoolStatus::Suspended, $this->school->fresh()->status);
        $this->actingAs($this->admin, 'platform')->post($this->platform("/schools/{$this->school->id}/billing/reactivate"))->assertSessionHasNoErrors();
        $this->assertSame(SchoolStatus::Active, $this->school->fresh()->status);

        $this->actingAs($this->admin, 'platform')->post($this->platform("/invoices/{$invoice->id}/payments"), ['amount' => '3000', 'method' => 'bank'])
            ->assertSessionHasErrors('transaction_id'); // bank payments need a reference
        $this->actingAs($this->admin, 'platform')->post($this->platform("/invoices/{$invoice->id}/payments"), ['amount' => '3000', 'method' => 'bank', 'transaction_id' => 'DBBL-1'])
            ->assertSessionHasNoErrors();
        $this->assertSame('paid', $invoice->fresh()->status);

        $this->actingAs($this->admin, 'platform')->get($this->platform("/schools/{$this->school->id}"))->assertOk()->assertInertia(fn ($p) => $p
            ->where('billing.subscription.status', 'active')->where('billing.subscription.plan.code', 'standard')
            ->has('billing.invoices', 1)->where('billing.invoices.0.status', 'paid')->where('billing.limits.students', 300)->has('billing.usage'));
        $this->actingAs($this->admin, 'platform')->get($this->platform('/subscriptions'))->assertOk()->assertInertia(fn ($p) => $p->where('subscriptions.data.0.status', 'active'));
        $this->actingAs($this->admin, 'platform')->get($this->platform('/invoices?status=paid'))->assertOk()->assertInertia(fn ($p) => $p->has('invoices.data', 1));
        $this->actingAs($this->admin, 'platform')->get($this->platform("/invoices/{$invoice->id}"))->assertOk()->assertInertia(fn ($p) => $p->has('payments', 1));
        $this->actingAs($this->admin, 'platform')->get($this->platform('/dashboard'))->assertOk()->assertInertia(fn ($p) => $p->where('billing.mrr', '3000.00'));
    }

    public function test_plan_validation(): void
    {
        $this->actingAs($this->admin, 'platform')->post($this->platform('/plans'), [
            'code' => 'Bad Code!', 'name' => '', 'price_monthly' => '-1', 'price_yearly' => 'x', 'trial_days' => 999, 'grace_days' => 1,
            'features' => ['students' => -5, 'website' => 'maybe'],
        ])->assertSessionHasErrors(['code', 'name', 'price_monthly', 'price_yearly', 'trial_days', 'features.students', 'features.website']);
    }

    public function test_roles_limit_billing_and_support_screens(): void
    {
        $plan = $this->plan();
        $agent = PlatformUser::factory()->role(PlatformRole::SupportAgent)->create();
        $billing = PlatformUser::factory()->role(PlatformRole::BillingSupport)->create();
        $auditor = PlatformUser::factory()->role(PlatformRole::Auditor)->create();

        $this->actingAs($agent, 'platform')->get($this->platform('/plans'))->assertForbidden();
        $this->actingAs($agent, 'platform')->post($this->platform("/schools/{$this->school->id}/billing/assign"), ['plan_id' => $plan->id, 'billing_cycle' => 'monthly'])->assertForbidden();
        $this->actingAs($auditor, 'platform')->get($this->platform('/invoices'))->assertOk();
        $this->actingAs($auditor, 'platform')->put($this->platform("/plans/{$plan->id}"), ['code' => 'x'])->assertForbidden();
        $this->actingAs($billing, 'platform')->post($this->platform("/schools/{$this->school->id}/billing/assign"), ['plan_id' => $plan->id, 'billing_cycle' => 'yearly'])->assertSessionHasNoErrors();
        $this->actingAs($billing, 'platform')->get($this->platform('/support'))->assertForbidden();
    }

    public function test_school_sees_only_its_own_subscription_and_invoices(): void
    {
        $plan = $this->plan('basic', ['students' => 100]);
        $beta = $this->provisionSchool('beta');
        // Set up directly (a platform request in the same test would leave the platform guard as the default).
        app(\App\Modules\Billing\Services\SubscriptionService::class)->assign($this->school, $plan, 'monthly');
        app(\App\Modules\Billing\Services\SubscriptionService::class)->assign($beta, $plan, 'yearly');
        $alphaInvoice = PlatformInvoice::query()->where('school_id', $this->school->id)->sole();
        $betaInvoice = PlatformInvoice::query()->where('school_id', $beta->id)->sole();

        $this->onSchool($this->school);
        $owner = $this->ownerOf($this->school);
        $this->actingAs($owner)->get(route('subscription.show'))->assertOk()->assertInertia(fn ($p) => $p
            ->component('app/subscription/show')->where('subscription.billing_cycle', 'monthly')->has('invoices', 1)
            ->where('invoices.0.id', $alphaInvoice->id)->where('features.0.key', 'students')->where('features.0.value', 100));
        $this->actingAs($owner)->get(route('subscription.invoice', $alphaInvoice->id))->assertOk();
        $this->actingAs($owner)->get(route('subscription.invoice', $betaInvoice->id))->assertNotFound();

        // Teachers do not see billing.
        $teacher = \App\Models\User::factory()->create();
        $teacher->assignRole('Teacher');
        $this->actingAs($teacher)->get(route('subscription.show'))->assertForbidden();

        // The trial/overdue banner is shared only with people who can act on it.
        Subscription::query()->where('school_id', $this->school->id)->update(['status' => 'past_due', 'grace_ends_at' => now()->addDays(3)]);
        $this->actingAs($owner)->get(route('dashboard'))->assertInertia(fn ($p) => $p->where('subscriptionNotice.status', 'past_due'));
        $this->actingAs($teacher)->get(route('dashboard'))->assertInertia(fn ($p) => $p->where('subscriptionNotice', null));
    }
}
