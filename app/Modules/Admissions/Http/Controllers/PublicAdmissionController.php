<?php

namespace App\Modules\Admissions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academics\Models\AcademicSession;
use App\Modules\Academics\Models\SchoolClass;
use App\Modules\Admissions\Http\Requests\PublicAdmissionRequest;
use App\Modules\Admissions\Services\AdmissionService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public admission form on the school's website. Exposes only class names and
 * the current session name; never any student data.
 */
class PublicAdmissionController extends Controller
{
    public function create(TenantContext $tenant): Response
    {
        $school = $tenant->require();

        return Inertia::render('tenant/public/admission', [
            'school' => ['name' => $school->name, 'name_bn' => $school->name_bn],
            'session' => AcademicSession::query()->where('is_current', true)->value('name'),
            'classes' => SchoolClass::query()->where('is_active', true)->orderBy('level_order')->get(['id', 'name', 'name_bn'])
                ->map(fn ($c) => $c->only(['id', 'name', 'name_bn'])),
            'submittedNo' => session('admission_no'),
        ]);
    }

    public function store(PublicAdmissionRequest $request, AdmissionService $admissions): RedirectResponse
    {
        $application = $admissions->submit($request->safe()->except('website'));

        return to_route('public.admission')->with('admission_no', $application->application_no);
    }
}
