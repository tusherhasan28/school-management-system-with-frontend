<?php

namespace App\Services\Tenancy;

use App\Models\User;
use App\Models\UserInvitation;
use App\Notifications\UserInvited;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Account invitations: the school creates the account, the person sets their
 * own password through a single-use, expiring link on the school's host.
 */
class InvitationService
{
    public const TTL_DAYS = 7;

    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * Create an invited (passwordless) account.
     */
    public function createInvitedUser(string $name, ?string $email, ?string $phone = null, ?string $role = null): User
    {
        $user = new User([
            'name' => $name,
            'email' => $email ? Str::lower($email) : null,
            'phone' => $phone,
            // Unusable random password until the invitation is accepted.
            'password' => Str::password(40),
        ]);
        $user->status = 'invited';
        $user->save();

        if ($role) {
            $user->assignRole($role);
        }

        return $user;
    }

    /**
     * Issue (or re-issue) an invitation and email it. Returns the plain token
     * so callers in tests/commands can build the link; it is never stored.
     */
    public function invite(User $user): string
    {
        $school = $this->tenant->require();
        $token = Str::random(64);

        DB::transaction(function () use ($user, $token) {
            // Previous unused invitations stop working.
            UserInvitation::query()->where('user_id', $user->id)->whereNull('accepted_at')->delete();

            $invitation = new UserInvitation;
            $invitation->user_id = $user->id;
            $invitation->token_hash = hash('sha256', $token);
            $invitation->expires_at = now()->addDays(self::TTL_DAYS);
            $invitation->invited_by = Auth::guard('web')->id();
            $invitation->save();
        });

        if ($user->email) {
            $user->notify(new UserInvited($school->name, $school->url('invitation/'.$token)));
        }

        return $token;
    }

    public function find(string $token): ?UserInvitation
    {
        $invitation = UserInvitation::query()
            ->with('user')
            ->where('token_hash', hash('sha256', $token))
            ->first();

        return $invitation?->isUsable() ? $invitation : null;
    }

    public function accept(UserInvitation $invitation, string $password): User
    {
        return DB::transaction(function () use ($invitation, $password) {
            $invitation->accepted_at = now();
            $invitation->save();

            $user = $invitation->user;
            $user->password = $password;
            $user->status = 'active';
            $user->email_verified_at ??= now();
            $user->save();

            return $user;
        });
    }
}
