<?php

namespace App\Http\Middleware;

use App\Modules\People\Models\StaffMember;
use App\Modules\Portal\Services\PortalAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the portals: `portal:parent` needs the Parent role and a linked
 * guardian record, `portal:student` the Student role and a linked student
 * record, `portal:teacher` an active staff record.
 */
class EnsurePortalArea
{
    public function __construct(private readonly PortalAccess $access) {}

    public function handle(Request $request, Closure $next, string $area): Response
    {
        $user = $request->user();

        $allowed = $user !== null && match ($area) {
            'parent' => $user->hasRole('Parent') && $this->access->guardianFor($user) !== null,
            'student' => $user->hasRole('Student') && $this->access->studentFor($user) !== null,
            'teacher' => StaffMember::query()->where('user_id', $user->id)->where('status', 'active')->exists(),
            default => false,
        };

        abort_unless($allowed, 403);

        // The school's plan must include this portal (teachers are always allowed).
        $feature = ['parent' => 'parent_portal', 'student' => 'student_portal'][$area] ?? null;
        abort_if($feature !== null && ! app(\App\Modules\Billing\Services\Entitlements::class)->enabled($feature), 403, __('billing.portal_not_in_plan'));

        return $next($request);
    }
}
