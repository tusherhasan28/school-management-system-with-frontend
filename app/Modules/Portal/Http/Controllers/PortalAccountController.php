<?php

namespace App\Modules\Portal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\People\Models\Guardian;
use App\Modules\People\Models\Student;
use App\Modules\Portal\Services\PortalAccounts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Staff screens: give guardians and students a portal login, or take it away. */
class PortalAccountController extends Controller
{
    public function __construct(private readonly PortalAccounts $accounts) {}

    public function inviteGuardian(Request $request, Guardian $guardian): RedirectResponse
    {
        $this->authorize('users.manage');
        $user = $this->accounts->inviteGuardian($guardian);

        return $this->done($user->status === 'invited' ? __('website.portal_invited', ['email' => $user->email]) : __('website.portal_enabled'));
    }

    public function disableGuardian(Request $request, Guardian $guardian): RedirectResponse
    {
        $this->authorize('users.manage');
        $this->accounts->disable($guardian);

        return $this->done(__('website.portal_disabled'));
    }

    public function inviteStudent(Request $request, Student $student): RedirectResponse
    {
        $this->authorize('users.manage');
        $user = $this->accounts->inviteStudent($student);

        return $this->done($user->status === 'invited' ? __('website.portal_invited', ['email' => $user->email]) : __('website.portal_enabled'));
    }

    public function disableStudent(Request $request, Student $student): RedirectResponse
    {
        $this->authorize('users.manage');
        $this->accounts->disable($student);

        return $this->done(__('website.portal_disabled'));
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
