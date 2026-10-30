<?php

namespace App\Modules\People\Services;

use App\Models\User;
use App\Modules\People\Models\StaffMember;
use App\Services\Audit\AuditLogger;
use App\Services\Tenancy\InvitationService;
use App\Support\Permissions\TenantPermissions;
use App\Support\Tenancy\TenantSequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StaffService
{
    public function __construct(
        private readonly InvitationService $invitations,
        private readonly TenantSequence $sequence,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?string $loginRole = null): StaffMember
    {
        return DB::transaction(function () use ($data, $loginRole) {
            $data['employee_no'] = ($data['employee_no'] ?? null) ?: $this->sequence->format('employee_no', 'EMP-{seq:4}');
            $staff = StaffMember::create($data);
            $this->audit->tenant('staff.created', $staff, new: $staff->only(['employee_no', 'full_name', 'staff_type', 'designation']));

            if ($loginRole) {
                $this->createLogin($staff, $loginRole);
            }

            return $staff;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(StaffMember $staff, array $data): StaffMember
    {
        $old = $staff->only(array_keys($data));
        $staff->update($data);
        $this->audit->tenant('staff.updated', $staff, $old, $data);

        return $staff;
    }

    /**
     * Give a staff member a login (invited account) with one of the staff roles.
     */
    public function createLogin(StaffMember $staff, string $role): User
    {
        if (! in_array($role, TenantPermissions::staffRoles(), true)) {
            throw ValidationException::withMessages(['role' => __('people.invalid_role')]);
        }

        if ($staff->user_id) {
            throw ValidationException::withMessages(['role' => __('people.has_login')]);
        }

        if (! $staff->email) {
            throw ValidationException::withMessages(['email' => __('people.email_required_for_login')]);
        }

        if (User::query()->where('email', Str::lower($staff->email))->exists()) {
            throw ValidationException::withMessages(['email' => __('people.email_in_use')]);
        }

        return DB::transaction(function () use ($staff, $role) {
            $user = $this->invitations->createInvitedUser($staff->full_name, $staff->email, $staff->phone, $role);
            $staff->forceFill(['user_id' => $user->id])->save();
            $this->invitations->invite($user);
            $this->audit->tenant('staff.login_created', $staff, new: ['role' => $role]);

            return $user;
        });
    }
}
