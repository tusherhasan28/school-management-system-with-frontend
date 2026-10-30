<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\InvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Accept an invitation on the school's host: set a password and sign in.
 * The invitation lookup is tenant scoped, so a token from another school
 * does not resolve here.
 */
class InvitationController extends Controller
{
    public function __construct(private readonly InvitationService $invitations) {}

    public function show(string $token): Response
    {
        $invitation = $this->invitations->find($token);

        return Inertia::render('auth/accept-invitation', [
            'token' => $token,
            'valid' => $invitation !== null,
            'name' => $invitation?->user->name,
            'email' => $invitation?->user->email,
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->invitations->find($token);

        if ($invitation === null) {
            return back()->withErrors(['password' => __('invitation.invalid')]);
        }

        $request->validate(['password' => ['required', 'confirmed', Password::defaults()]]);

        $user = $this->invitations->accept($invitation, $request->string('password'));
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('invitation.accepted')]);

        return to_route('dashboard');
    }
}
