<?php

namespace Tests\Feature\People;

use App\Models\User;
use App\Models\UserInvitation;
use App\Modules\People\Models\StaffMember;
use App\Notifications\UserInvited;
use App\Services\Tenancy\InvitationService;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesAcademicStructure;
use Tests\TenantTestCase;

class StaffAndInvitationTest extends TenantTestCase
{
    use CreatesAcademicStructure;

    public function test_staff_with_login_role_gets_an_invited_account_and_email(): void
    {
        Notification::fake();

        $this->actingAs($this->ownerOf($this->school))
            ->post(route('staff.store'), [
                'staff_type' => 'teacher', 'full_name' => 'Salma Begum', 'gender' => 'female',
                'email' => 'Salma@Example.com', 'phone' => '01744444444', 'login_role' => 'Teacher',
            ])
            ->assertSessionHasNoErrors();

        $staff = StaffMember::query()->sole();
        $this->assertSame('EMP-0001', $staff->employee_no);

        $user = User::query()->where('email', 'salma@example.com')->sole();
        $this->assertSame('invited', $user->status);
        $this->assertTrue($user->hasRole('Teacher'));
        $this->assertSame($user->id, $staff->user_id);

        Notification::assertSentTo($user, UserInvited::class, fn (UserInvited $n) => str_starts_with($n->url, 'http://alpha.schoolsaas.test/invitation/'));
        $this->assertSame(64, strlen(UserInvitation::query()->sole()->token_hash), 'Only a hash is stored.');
    }

    public function test_invited_user_cannot_log_in_until_accepting(): void
    {
        $user = app(InvitationService::class)->createInvitedUser('New Teacher', 'new@example.com', role: 'Teacher');
        $token = app(InvitationService::class)->invite($user);

        $this->post(route('login.store'), ['email' => 'new@example.com', 'password' => 'whatever'])->assertSessionHasErrors('email');

        $this->get(route('invitation.show', $token))->assertInertia(fn ($p) => $p->component('auth/accept-invitation')->where('valid', true)->where('email', 'new@example.com'));

        $this->post(route('invitation.accept', $token), ['password' => 'Str0ng-Password!', 'password_confirmation' => 'Str0ng-Password!'])
            ->assertRedirect(route('portal.home'));

        $this->assertAuthenticatedAs($user->fresh());
        $this->assertSame('active', $user->fresh()->status);

        // Single use.
        auth()->logout();
        $this->get(route('invitation.show', $token))->assertInertia(fn ($p) => $p->where('valid', false));
    }

    public function test_invitation_token_does_not_work_on_another_school(): void
    {
        $user = app(InvitationService::class)->createInvitedUser('Teacher', 't@example.com');
        $token = app(InvitationService::class)->invite($user);

        $beta = $this->provisionSchool('beta');

        $this->get($this->schoolUrl($beta, '/invitation/'.$token))->assertInertia(fn ($p) => $p->where('valid', false));
        $this->post($this->schoolUrl($beta, '/invitation/'.$token), ['password' => 'Str0ng-Password!', 'password_confirmation' => 'Str0ng-Password!'])
            ->assertSessionHasErrors('password');
        $this->assertGuest();
    }

    public function test_expired_invitation_is_invalid_and_reinvite_replaces_old_token(): void
    {
        $service = app(InvitationService::class);
        $user = $service->createInvitedUser('Teacher', 't@example.com');
        $old = $service->invite($user);
        $new = $service->invite($user);

        $this->assertNull($service->find($old));
        $this->assertNotNull($service->find($new));

        $this->travel(InvitationService::TTL_DAYS + 1)->days();
        $this->assertNull($service->find($new));
    }

    public function test_login_can_be_added_later_and_only_once(): void
    {
        Notification::fake();
        $owner = $this->ownerOf($this->school);
        $staff = StaffMember::create(['staff_type' => 'staff', 'employee_no' => 'S-1', 'full_name' => 'Office', 'email' => 'office@example.com']);

        $this->actingAs($owner)->post(route('staff.login', $staff), ['role' => 'Receptionist'])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('staff.login', $staff), ['role' => 'Receptionist'])->assertSessionHasErrors('role');
        $this->actingAs($owner)->post(route('staff.login', $staff), ['role' => 'School Owner'])->assertSessionHasErrors('role');
        $this->assertSame(1, User::query()->where('email', 'office@example.com')->count());
    }

    public function test_principal_cannot_manage_staff_and_other_schools_staff_is_hidden(): void
    {
        $staff = StaffMember::create(['staff_type' => 'teacher', 'employee_no' => 'T-1', 'full_name' => 'Teacher One']);

        $this->actingAs($this->userWithRole('Principal'))->get(route('staff.edit', $staff))->assertOk();
        $this->actingAs($this->userWithRole('Principal'))->put(route('staff.update', $staff), ['staff_type' => 'teacher', 'full_name' => 'X', 'gender' => 'male'])->assertForbidden();

        $beta = $this->provisionSchool('beta');
        // The test client shares one cookie jar across hosts; browsers do not.
        $this->flushSession();
        $betaOwner = $this->ownerOf($beta);
        $this->actingAs($betaOwner)->get($this->schoolUrl($beta, '/app/staff/'.$staff->id.'/edit'))->assertNotFound();
    }
}
