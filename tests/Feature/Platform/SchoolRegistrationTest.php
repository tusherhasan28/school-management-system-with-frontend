<?php

namespace Tests\Feature\Platform;

use App\Enums\PlatformRole;
use App\Enums\SchoolStatus;
use App\Models\PlatformUser;
use App\Models\School;
use App\Models\SchoolRegistration;
use App\Models\User;
use App\Notifications\SchoolRegistrationReceived;
use App\Notifications\UserInvited;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SchoolRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function form(array $o = []): array
    {
        return $o + [
            'institution_name' => 'Sylhet Ideal Kindergarten', 'institution_name_bn' => 'সিলেট আইডিয়াল কিন্ডারগার্টেন',
            'requested_slug' => 'Sylhet-Ideal', 'institution_type' => 'kindergarten', 'contact_name' => 'Nasrin Akter',
            'email' => 'nasrin@example.com', 'phone' => '01811000000', 'district' => 'Sylhet', 'approx_students' => 180,
        ];
    }

    public function test_institution_can_register_on_the_marketing_site(): void
    {
        Notification::fake();

        $this->post('http://schoolsaas.test/register-school', $this->form())
            ->assertRedirect('http://schoolsaas.test/register-school')
            ->assertSessionHas('registration_submitted', true);

        $registration = SchoolRegistration::query()->sole();
        $this->assertSame('sylhet-ideal', $registration->requested_slug);
        $this->assertSame('pending', $registration->status);
        Notification::assertSentOnDemand(SchoolRegistrationReceived::class);
        $this->assertSame(0, School::query()->count(), 'No school until a platform admin approves.');
    }

    public function test_reserved_taken_or_invalid_slugs_are_refused(): void
    {
        $this->provisionSchool('alpha');

        $this->post('http://schoolsaas.test/register-school', $this->form(['requested_slug' => 'admin']))->assertSessionHasErrors('requested_slug');
        $this->post('http://schoolsaas.test/register-school', $this->form(['requested_slug' => 'alpha']))->assertSessionHasErrors('requested_slug');
        $this->post('http://schoolsaas.test/register-school', $this->form(['requested_slug' => '-bad-']))->assertSessionHasErrors('requested_slug');

        $this->getJson('http://schoolsaas.test/register-school/check-slug?slug=alpha')->assertJson(['available' => false]);
        $this->getJson('http://schoolsaas.test/register-school/check-slug?slug=new-school')->assertJson(['available' => true]);
    }

    public function test_registration_form_is_only_on_the_root_domain(): void
    {
        $this->provisionSchool('alpha');

        $this->post('http://alpha.schoolsaas.test/register-school', $this->form())->assertNotFound();
        $this->assertSame(0, SchoolRegistration::query()->count());
    }

    public function test_approval_reminds_to_add_the_subdomain_on_hosts_without_wildcards(): void
    {
        Notification::fake();
        config(['tenancy.manual_subdomains' => true]);
        $registration = SchoolRegistration::create($this->form(['requested_slug' => 'khulna-high']));

        $this->actingAs(PlatformUser::factory()->create(), 'platform')
            ->post($this->platformUrl('/platform/registrations/'.$registration->id.'/approve'))
            ->assertSessionHasNoErrors();

        $flash = session(\Inertia\Support\SessionKey::FLASH_DATA);
        $this->assertStringContainsString('khulna-high.'.config('tenancy.domain'), json_encode($flash, JSON_UNESCAPED_SLASHES));
    }

    public function test_approval_provisions_a_trial_school_and_invites_the_contact(): void
    {
        Notification::fake();
        $registration = SchoolRegistration::create($this->form(['requested_slug' => 'sylhet-ideal']));

        $this->actingAs(PlatformUser::factory()->create(), 'platform')
            ->post($this->platformUrl('/platform/registrations/'.$registration->id.'/approve'), ['slug' => 'sylhet-kg'])
            ->assertSessionHasNoErrors();

        $school = School::query()->where('slug', 'sylhet-kg')->sole();
        $this->assertSame(SchoolStatus::Trial, $school->status);
        $this->assertSame('kindergarten', $school->institution_type);
        $this->assertSame('Sylhet', $school->district);

        $registration->refresh();
        $this->assertSame(['approved', $school->id], [$registration->status, $registration->school_id]);

        $owner = app(TenantContext::class)->run($school, fn () => User::query()->where('email', 'nasrin@example.com')->sole());
        $this->assertSame('invited', $owner->status);
        $this->assertTrue(app(TenantContext::class)->run($school, fn () => $owner->hasRole('School Owner')));
        Notification::assertSentTo($owner, UserInvited::class, fn (UserInvited $n) => str_starts_with($n->url, 'http://sylhet-kg.schoolsaas.test/invitation/'));

        $this->get('http://sylhet-kg.schoolsaas.test/login')->assertOk();
        $this->assertDatabaseHas('platform_activity_logs', ['action' => 'registration.approved', 'school_id' => $school->id]);
    }

    public function test_rejection_and_double_processing(): void
    {
        $registration = SchoolRegistration::create($this->form(['requested_slug' => 'sylhet-ideal']));
        $admin = PlatformUser::factory()->create();

        $this->actingAs($admin, 'platform')->post($this->platformUrl('/platform/registrations/'.$registration->id.'/reject'), [])->assertSessionHasErrors('reason');
        $this->actingAs($admin, 'platform')->post($this->platformUrl('/platform/registrations/'.$registration->id.'/reject'), ['reason' => 'Duplicate'])->assertSessionHasNoErrors();
        $this->actingAs($admin, 'platform')->post($this->platformUrl('/platform/registrations/'.$registration->id.'/approve'))->assertSessionHasErrors('status');

        $this->assertSame(0, School::query()->count());
    }

    public function test_support_agent_can_list_but_not_approve(): void
    {
        $registration = SchoolRegistration::create($this->form(['requested_slug' => 'sylhet-ideal']));
        $agent = PlatformUser::factory()->role(PlatformRole::SupportAgent)->create();

        $this->actingAs($agent, 'platform')->get($this->platformUrl('/platform/registrations'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('platform/registrations/index')->has('registrations.data', 1));
        $this->actingAs($agent, 'platform')->post($this->platformUrl('/platform/registrations/'.$registration->id.'/approve'))->assertForbidden();
    }
}
