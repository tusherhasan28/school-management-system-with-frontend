<?php

namespace App\Http\Controllers\Platform;

use App\Enums\SchoolStatus;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Services\Tenancy\SchoolLifecycle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class SchoolStatusController extends Controller
{
    public function __invoke(Request $request, School $school, SchoolLifecycle $lifecycle): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(SchoolStatus::class)->only([SchoolStatus::Active, SchoolStatus::Suspended, SchoolStatus::Archived])],
            'reason' => ['required_if:status,suspended', 'nullable', 'string', 'max:255'],
        ]);

        $lifecycle->transition($school, SchoolStatus::from($data['status']), $data['reason'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('school.status_changed')]);

        return back();
    }
}
