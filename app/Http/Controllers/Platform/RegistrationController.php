<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\SchoolRegistration;
use App\Services\Tenancy\SchoolRegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RegistrationController extends Controller
{
    public function __construct(private readonly SchoolRegistrationService $registrations) {}

    public function index(Request $request): Response
    {
        $status = $request->validate(['status' => ['nullable', Rule::in(['pending', 'approved', 'rejected'])]])['status'] ?? 'pending';

        return Inertia::render('platform/registrations/index', [
            'registrations' => SchoolRegistration::query()->where('status', $status)->latest()->paginate(20)->withQueryString()
                ->through(fn (SchoolRegistration $r) => $r->only([
                    'id', 'institution_name', 'institution_name_bn', 'requested_slug', 'institution_type', 'contact_name',
                    'email', 'phone', 'district', 'approx_students', 'status', 'rejection_reason', 'school_id',
                ]) + ['created_at' => $r->created_at?->toIso8601String()]),
            'status' => $status,
            'domain' => config('tenancy.domain'),
            'pendingCount' => SchoolRegistration::query()->where('status', 'pending')->count(),
        ]);
    }

    public function approve(Request $request, SchoolRegistration $registration): RedirectResponse
    {
        $data = $request->validate(['slug' => ['nullable', 'string', 'min:3', 'max:63', 'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])$/']]);
        $this->registrations->approve($registration, $data['slug'] ?? null);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('registration.approved')]);

        return back();
    }

    public function reject(Request $request, SchoolRegistration $registration): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->registrations->reject($registration, $data['reason']);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('registration.rejected')]);

        return back();
    }
}
