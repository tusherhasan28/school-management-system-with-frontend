<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Models\PlanFeature;
use App\Modules\Billing\Services\Entitlements;
use App\Modules\Billing\Services\SubscriptionService;
use App\Modules\People\Models\StaffMember;
use App\Modules\People\Models\Student;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesAcademicStructure;
use Tests\Concerns\CreatesPlans;
use Tests\TenantTestCase;

/**
 * Plan limits and switches come from the database for the school resolved
 * from the host, and are enforced on the server.
 */
class EntitlementsTest extends TenantTestCase
{
    use CreatesAcademicStructure, CreatesPlans;

    private function subscribe(array $features): void
    {
        app(SubscriptionService::class)->assign($this->school, $this->plan('small', $features), 'monthly');
    }

    private function studentPayload(array $s, string $name): array
    {
        return [
            'full_name' => $name, 'gender' => 'female', 'academic_session_id' => $s['session']->id, 'class_id' => $s['class6']->id, 'section_id' => $s['sectionA']->id,
            'guardians' => [['full_name' => 'Guardian '.$name, 'phone' => '0171'.random_int(1000000, 9999999), 'relationship' => 'mother', 'is_primary' => true]],
        ];
    }

    public function test_school_without_subscription_is_not_limited(): void
    {
        $plan = app(Entitlements::class);
        $this->assertNull($plan->limit('students'));
        $this->assertTrue($plan->enabled('sms'));
    }

    public function test_student_and_staff_limits_are_enforced_on_every_path(): void
    {
        $s = $this->academicStructure();
        $this->subscribe(['students' => 2, 'staff' => 1]);
        $owner = $this->ownerOf($this->school);

        $this->actingAs($owner)->post(route('students.store'), $this->studentPayload($s, 'One'))->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('students.store'), $this->studentPayload($s, 'Two'))->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('students.store'), $this->studentPayload($s, 'Three'))->assertSessionHasErrors('limit');
        $this->assertSame(2, Student::query()->count());

        // Extra fields in the request change nothing: limits are not read from input.
        $this->actingAs($owner)->post(route('students.store'), $this->studentPayload($s, 'Four') + ['limit' => 999, 'students' => 999, 'plan_id' => 1])
            ->assertSessionHasErrors('limit');

        $staff = ['staff_type' => 'teacher', 'full_name' => 'T1', 'gender' => 'female'];
        $this->actingAs($owner)->post(route('staff.store'), $staff)->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('staff.store'), ['full_name' => 'T2'] + $staff)->assertSessionHasErrors('limit');
        $this->assertSame(1, StaffMember::query()->count());

        // A graduated student frees a place.
        Student::query()->first()->forceFill(['status' => 'graduated'])->save();
        $this->actingAs($owner)->post(route('students.store'), $this->studentPayload($s, 'Five'))->assertSessionHasNoErrors();
    }

    public function test_switches_gate_website_portals_import_and_online_payments(): void
    {
        Notification::fake();
        $s = $this->academicStructure();
        $student = $this->makeStudent($s['session'], $s['class6'], $s['sectionA']);
        $this->subscribe(['website' => false, 'parent_portal' => false, 'bulk_import' => false, 'online_payments' => false]);
        $owner = $this->ownerOf($this->school);

        $this->actingAs($owner)->put(route('website.update'), ['template' => 'classic', 'theme_color' => '#0f766e', 'default_language' => 'bn', 'is_published' => true])
            ->assertSessionHasErrors('plan');
        $this->actingAs($owner)->put(route('website.update'), ['template' => 'classic', 'theme_color' => '#0f766e', 'default_language' => 'bn', 'is_published' => false])
            ->assertSessionHasNoErrors();

        $guardian = $student->guardians()->first();
        $guardian->update(['email' => 'g@example.com']);
        $this->actingAs($owner)->post(route('guardians.portal.store', $guardian))->assertSessionHasErrors('plan');
        $this->actingAs($owner)->post(route('imports.store'), [])->assertSessionHasErrors('plan');
        $this->assertSame([], app(\App\Modules\Finance\Services\OnlinePaymentService::class)->available());

        // A plan change on the platform side takes effect at once.
        PlanFeature::query()->where('feature_key', 'parent_portal')->update(['feature_value' => json_encode(true)]);
        $this->actingAs($owner)->post(route('guardians.portal.store', $guardian))->assertSessionHasNoErrors();
    }

    public function test_existing_portal_logins_lose_access_when_the_plan_drops_the_portal(): void
    {
        Notification::fake();
        $s = $this->academicStructure();
        $student = $this->makeStudent($s['session'], $s['class6'], $s['sectionA']);
        $this->subscribe([]);
        $guardian = $student->guardians()->first();
        $guardian->update(['email' => 'g@example.com']);
        $user = app(\App\Modules\Portal\Services\PortalAccounts::class)->inviteGuardian($guardian->fresh());
        $user->forceFill(['status' => 'active', 'email_verified_at' => now()])->save();

        $this->actingAs($user)->get(route('parent.child', $student->id))->assertOk();
        PlanFeature::query()->where('feature_key', 'parent_portal')->update(['feature_value' => json_encode(false)]);
        $this->actingAs($user)->get(route('parent.child', $student->id))->assertForbidden();
    }

    public function test_school_hosts_have_no_route_to_change_plans(): void
    {
        $owner = $this->ownerOf($this->school);
        $this->actingAs($owner)->get('/platform/plans')->assertNotFound();
        $this->actingAs($owner)->post('/platform/plans', ['code' => 'x'])->assertNotFound();
    }
}
