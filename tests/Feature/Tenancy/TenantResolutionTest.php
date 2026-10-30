<?php

namespace Tests\Feature\Tenancy;

use App\Enums\SchoolStatus;
use App\Modules\Setup\Models\Campus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TenantResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_subdomain_gets_a_404(): void
    {
        $this->get('http://no-such-school.schoolsaas.test/')->assertNotFound();
        $this->get('http://no-such-school.schoolsaas.test/login')->assertNotFound();
    }

    public function test_unrelated_hosts_get_a_404(): void
    {
        $this->provisionSchool('alpha');

        $this->get('http://alpha.evil.test/')->assertNotFound();
        $this->get('http://alpha.schoolsaas.test.evil.test/login')->assertNotFound();
    }

    public function test_known_school_resolves_by_host(): void
    {
        $this->provisionSchool('alpha');

        $this->get('http://alpha.schoolsaas.test/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('tenant/public/home')
                ->where('school.name', 'Alpha School')
                ->missing('school.id'));
    }

    public function test_host_matching_is_case_insensitive(): void
    {
        $this->provisionSchool('alpha');

        $this->get('http://ALPHA.SchoolSaaS.test/')->assertOk();
    }

    public function test_tenant_paths_are_not_served_on_root_or_admin_host(): void
    {
        $this->provisionSchool('alpha');

        $this->get('http://schoolsaas.test/app/dashboard')->assertNotFound();
        $this->get('http://admin.schoolsaas.test/app/dashboard')->assertNotFound();
        $this->get('http://admin.schoolsaas.test/login')->assertNotFound();
    }

    public function test_marketing_site_is_served_on_root_host(): void
    {
        $this->get('http://schoolsaas.test/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('marketing/home')->where('school', null));
    }

    /**
     * @return array<string, array{SchoolStatus}>
     */
    public static function inaccessibleStatuses(): array
    {
        return [
            'pending' => [SchoolStatus::Pending],
            'suspended' => [SchoolStatus::Suspended],
            'cancelled' => [SchoolStatus::Cancelled],
            'archived' => [SchoolStatus::Archived],
        ];
    }

    #[DataProvider('inaccessibleStatuses')]
    public function test_inaccessible_school_gets_generic_unavailable_page(SchoolStatus $status): void
    {
        $school = $this->provisionSchool('alpha', $status);

        foreach (['/', '/login', '/app/dashboard'] as $path) {
            $this->get($this->schoolUrl($school, $path))
                ->assertStatus(503)
                ->assertSee(__('school.unavailable_title'))
                ->assertDontSee('Alpha School');
        }

        // Suspension never deletes data.
        $this->assertSame(1, $this->inSchool($school, fn () => Campus::query()->count()));
    }

    public function test_reserved_slugs_cannot_be_provisioned(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->provisionSchool('admin');
    }
}
