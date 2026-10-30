<?php

namespace Tests\Feature\Platform;

use App\Enums\PlatformRole;
use App\Enums\SchoolStatus;
use App\Models\PlatformUser;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformAccessTest extends TestCase
{
    use RefreshDatabase;

    private School $alpha;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = $this->provisionSchool('alpha');
    }

    public function test_platform_login_page_renders_on_admin_host_only(): void
    {
        $this->get($this->platformUrl('/platform/login'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('platform/auth/login'));

        $this->get($this->schoolUrl($this->alpha, '/platform/login'))->assertNotFound();
    }

    public function test_platform_dashboard_requires_platform_login(): void
    {
        $this->get($this->platformUrl('/platform/dashboard'))
            ->assertRedirect($this->platformUrl('/platform/login'));
    }

    public function test_platform_user_can_log_in_and_see_dashboard(): void
    {
        $admin = PlatformUser::factory()->create(['email' => 'ops@schoolsaas.test']);

        $this->post($this->platformUrl('/platform/login'), ['email' => 'ops@schoolsaas.test', 'password' => 'password'])
            ->assertRedirect($this->platformUrl('/platform/dashboard'));

        $this->assertAuthenticatedAs($admin, 'platform');

        $this->get($this->platformUrl('/platform/dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('platform/dashboard')
                ->where('stats.schools', 1)
                ->where('school', null)
                ->where('auth.user', null));

        $this->assertDatabaseHas('platform_activity_logs', ['platform_user_id' => $admin->id, 'action' => 'platform.login']);
    }

    public function test_disabled_platform_user_cannot_log_in(): void
    {
        PlatformUser::factory()->create(['email' => 'gone@schoolsaas.test', 'status' => 'disabled']);

        $this->post($this->platformUrl('/platform/login'), ['email' => 'gone@schoolsaas.test', 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest('platform');
    }

    public function test_tenant_credentials_do_not_work_on_platform(): void
    {
        $this->post($this->platformUrl('/platform/login'), ['email' => 'owner@alpha.test', 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest('platform');
    }

    public function test_tenant_session_grants_no_platform_access(): void
    {
        $this->actingAs($this->ownerOf($this->alpha), 'web')
            ->get($this->platformUrl('/platform/dashboard'))
            ->assertRedirect($this->platformUrl('/platform/login'));
    }

    public function test_platform_session_grants_no_tenant_access(): void
    {
        $this->actingAs(PlatformUser::factory()->create(), 'platform')
            ->get($this->schoolUrl($this->alpha, '/app/dashboard'))
            ->assertRedirect($this->schoolUrl($this->alpha, '/login'));
    }

    public function test_administrator_can_suspend_and_reactivate_a_school(): void
    {
        $admin = PlatformUser::factory()->create();

        $this->actingAs($admin, 'platform')
            ->put($this->platformUrl('/platform/schools/'.$this->alpha->id.'/status'), ['status' => 'suspended', 'reason' => 'Unpaid invoice'])
            ->assertSessionHasNoErrors();

        $this->assertSame(SchoolStatus::Suspended, $this->alpha->fresh()->status);
        $this->get($this->schoolUrl($this->alpha, '/'))->assertStatus(503);

        $this->actingAs($admin, 'platform')
            ->put($this->platformUrl('/platform/schools/'.$this->alpha->id.'/status'), ['status' => 'active']);

        $this->assertSame(SchoolStatus::Active, $this->alpha->fresh()->status);
        $this->get($this->schoolUrl($this->alpha, '/'))->assertOk();
        $this->assertSame(2, \App\Models\PlatformActivityLog::query()->where('action', 'school.status_changed')->count());
    }

    public function test_suspension_requires_a_reason(): void
    {
        $this->actingAs(PlatformUser::factory()->create(), 'platform')
            ->put($this->platformUrl('/platform/schools/'.$this->alpha->id.'/status'), ['status' => 'suspended'])
            ->assertSessionHasErrors('reason');
    }

    public function test_support_agent_can_view_but_not_change_school_status(): void
    {
        $agent = PlatformUser::factory()->role(PlatformRole::SupportAgent)->create();

        $this->actingAs($agent, 'platform')->get($this->platformUrl('/platform/schools'))->assertOk();

        $this->actingAs($agent, 'platform')
            ->put($this->platformUrl('/platform/schools/'.$this->alpha->id.'/status'), ['status' => 'suspended', 'reason' => 'x'])
            ->assertForbidden();

        $this->assertSame(SchoolStatus::Active, $this->alpha->fresh()->status);
    }

    public function test_school_list_exposes_only_central_fields(): void
    {
        $this->actingAs(PlatformUser::factory()->create(), 'platform')
            ->get($this->platformUrl('/platform/schools?search=alpha'))
            ->assertInertia(fn ($page) => $page
                ->component('platform/schools/index')
                ->has('schools.data', 1)
                ->where('schools.data.0.slug', 'alpha')
                ->where('schools.data.0.url', 'http://alpha.schoolsaas.test/')
                ->missing('schools.data.0.users'));
    }
}
