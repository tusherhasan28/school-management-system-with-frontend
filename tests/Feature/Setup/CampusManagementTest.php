<?php

namespace Tests\Feature\Setup;

use App\Models\User;
use App\Modules\Setup\Models\Campus;
use Tests\TenantTestCase;

/**
 * Role-based access to the reference module inside one school.
 */
class CampusManagementTest extends TenantTestCase
{
    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_owner_can_create_update_and_delete_a_campus(): void
    {
        $owner = $this->ownerOf($this->school);

        $this->actingAs($owner)
            ->post(route('campuses.store'), ['name' => 'Mirpur', 'name_bn' => 'মিরপুর', 'code' => 'MRP', 'phone' => '01711000000'])
            ->assertSessionHasNoErrors();

        $campus = Campus::query()->where('code', 'MRP')->firstOrFail();
        $this->assertSame('মিরপুর', $campus->name_bn);

        $this->actingAs($owner)
            ->put(route('campuses.update', $campus), ['name' => 'Mirpur 10', 'code' => 'MRP'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Mirpur 10', $campus->fresh()->name);

        $this->actingAs($owner)->delete(route('campuses.destroy', $campus))->assertRedirect();
        $this->assertModelMissing($campus);
    }

    public function test_main_campus_cannot_be_deleted(): void
    {
        $main = Campus::query()->where('is_main', true)->firstOrFail();

        $this->actingAs($this->ownerOf($this->school))
            ->delete(route('campuses.destroy', $main))
            ->assertForbidden();
    }

    public function test_only_one_main_campus_at_a_time(): void
    {
        $this->actingAs($this->ownerOf($this->school))
            ->post(route('campuses.store'), ['name' => 'New HQ', 'is_main' => true])
            ->assertSessionHasNoErrors();

        $this->assertSame(['New HQ'], Campus::query()->where('is_main', true)->pluck('name')->all());
    }

    public function test_principal_can_view_but_not_manage(): void
    {
        $principal = $this->userWithRole('Principal');

        $this->actingAs($principal)->get(route('campuses.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('can.manage', false));

        $this->actingAs($principal)
            ->post(route('campuses.store'), ['name' => 'Nope'])
            ->assertForbidden();
    }

    public function test_teacher_cannot_view_campuses(): void
    {
        $this->actingAs($this->userWithRole('Teacher'))
            ->get(route('campuses.index'))
            ->assertForbidden();
    }

    public function test_validation_rejects_bad_input(): void
    {
        $this->actingAs($this->ownerOf($this->school))
            ->post(route('campuses.store'), ['name' => '', 'phone' => 'call me', 'code' => 'has spaces'])
            ->assertSessionHasErrors(['name', 'phone', 'code']);
    }

    public function test_disabled_user_is_logged_out(): void
    {
        $user = $this->userWithRole('School Admin');
        $user->forceFill(['status' => 'disabled'])->save();

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_search_filters_server_side(): void
    {
        Campus::factory()->create(['name' => 'Gulshan', 'code' => 'GUL']);
        Campus::factory()->create(['name' => 'Banani', 'code' => 'BAN']);

        $this->actingAs($this->ownerOf($this->school))
            ->get(route('campuses.index', ['search' => 'gul']))
            ->assertInertia(fn ($page) => $page->has('campuses.data', 1)->where('campuses.data.0.name', 'Gulshan'));
    }
}
