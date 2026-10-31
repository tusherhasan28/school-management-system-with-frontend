<?php

namespace App\Modules\Portal\Services;

use App\Models\User;
use App\Modules\People\Models\Guardian;
use App\Modules\People\Models\Student;
use App\Services\Tenancy\InvitationService;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Portal logins for guardians (role Parent) and students (role Student).
 *
 * The school creates the account and an invitation link is emailed; the
 * person sets their own password. A guardian whose email already belongs to
 * a login of this school (a teacher who is also a parent) gets the Parent
 * role added to that login instead of a second account.
 */
class PortalAccounts
{
    public function __construct(
        private readonly InvitationService $invitations,
        private readonly AuditLogger $audit,
    ) {}

    /** Create (or re-send, or re-enable) the guardian's parent login. */
    public function inviteGuardian(Guardian $guardian): User
    {
        app(\App\Modules\Billing\Services\Entitlements::class)->ensureEnabled('parent_portal');

        if ($guardian->user_id) {
            return $this->reactivate($guardian, 'Parent');
        }

        $email = $this->requireEmail($guardian->email);

        return DB::transaction(function () use ($guardian, $email) {
            $existing = User::query()->where('email', $email)->first();

            if ($existing !== null) {
                // Never take over a login that already belongs to another guardian or a student.
                if (Guardian::query()->where('user_id', $existing->id)->exists() || Student::query()->where('user_id', $existing->id)->exists()) {
                    throw ValidationException::withMessages(['email' => __('website.portal_email_in_use')]);
                }

                $existing->assignRole('Parent');
                $guardian->forceFill(['user_id' => $existing->id])->save();
                $this->audit->tenant('portal.parent_linked', $guardian, new: ['user_id' => $existing->id]);

                return $existing;
            }

            $user = $this->invitations->createInvitedUser($guardian->full_name, $email, $this->freePhone($guardian->phone), 'Parent');
            $guardian->forceFill(['user_id' => $user->id])->save();
            $this->invitations->invite($user);
            $this->audit->tenant('portal.parent_invited', $guardian, new: ['user_id' => $user->id]);

            return $user;
        });
    }

    /** Create (or re-send, or re-enable) the student's own login. */
    public function inviteStudent(Student $student): User
    {
        app(\App\Modules\Billing\Services\Entitlements::class)->ensureEnabled('student_portal');

        if ($student->user_id) {
            return $this->reactivate($student, 'Student');
        }

        $email = $this->requireEmail($student->email);

        if (User::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => __('website.portal_email_in_use')]);
        }

        return DB::transaction(function () use ($student, $email) {
            $user = $this->invitations->createInvitedUser($student->full_name, $email, null, 'Student');
            $student->forceFill(['user_id' => $user->id])->save();
            $this->invitations->invite($user);
            $this->audit->tenant('portal.student_invited', $student, new: ['user_id' => $user->id]);

            return $user;
        });
    }

    /**
     * Turn portal access off. A shared login (a teacher who is also a parent)
     * only loses the Parent role; a family-only login is disabled.
     */
    public function disable(Guardian|Student $holder): void
    {
        $user = $holder->user_id ? User::query()->find($holder->user_id) : null;

        if ($user === null) {
            return;
        }

        $role = $holder instanceof Guardian ? 'Parent' : 'Student';

        DB::transaction(function () use ($holder, $user, $role) {
            if ($user->getRoleNames()->diff(PortalAccess::FAMILY_ROLES)->isNotEmpty()) {
                $user->removeRole($role);
                $holder->forceFill(['user_id' => null])->save();
            } else {
                $user->status = 'disabled';
                $user->save();
            }

            $this->audit->tenant('portal.disabled', $holder, new: ['user_id' => $user->id]);
        });
    }

    /** Status of a holder's portal login for the staff screens. */
    public function status(Guardian|Student $holder): ?string
    {
        if (! $holder->user_id) {
            return null;
        }

        $user = User::query()->find($holder->user_id);
        $role = $holder instanceof Guardian ? 'Parent' : 'Student';

        if ($user === null || ! $user->hasRole($role)) {
            return null;
        }

        return $user->status; // invited | active | disabled | locked
    }

    private function reactivate(Guardian|Student $holder, string $role): User
    {
        $user = User::query()->findOrFail($holder->user_id);

        if (! $user->hasRole($role)) {
            $user->assignRole($role);
        }

        if ($user->status === 'invited') {
            $this->invitations->invite($user);
            $this->audit->tenant('portal.invitation_resent', $holder, new: ['user_id' => $user->id]);
        } elseif ($user->status === 'disabled') {
            $user->status = 'active';
            $user->save();
            $this->audit->tenant('portal.enabled', $holder, new: ['user_id' => $user->id]);
        } else {
            throw ValidationException::withMessages(['email' => __('website.portal_has_login')]);
        }

        return $user;
    }

    private function requireEmail(?string $email): string
    {
        if (! $email) {
            throw ValidationException::withMessages(['email' => __('website.portal_email_required')]);
        }

        return Str::lower($email);
    }

    /** Phone numbers are unique per school; skip one that is already taken. */
    private function freePhone(?string $phone): ?string
    {
        return $phone && ! User::query()->where('phone', $phone)->exists() ? $phone : null;
    }
}
