<?php

namespace App\Modules\Setup\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academics\Models\AcademicSession;
use App\Modules\Academics\Models\SchoolClass;
use App\Modules\Academics\Models\Subject;
use App\Modules\Setup\Services\SetupWizard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Resumable setup wizard for a new school (settings.manage).
 */
class SetupController extends Controller
{
    public function __construct(private readonly SetupWizard $wizard) {}

    public function show(Request $request, TenantContext $tenant): Response
    {
        $this->authorize('settings.manage');
        $school = $tenant->require();
        $year = (int) now($school->timezone)->format('Y');

        return Inertia::render('app/setup', [
            'progress' => $this->wizard->progress(),
            'steps' => SetupWizard::STEPS,
            'profile' => $school->only(['name', 'name_bn', 'institution_type', 'email', 'phone', 'address', 'district', 'division', 'locale']),
            'sessionSuggestion' => ['name' => (string) $year, 'start_date' => "{$year}-01-01", 'end_date' => "{$year}-12-31"],
            'currentSession' => AcademicSession::query()->where('is_current', true)->first(['id', 'name']),
            'classPreset' => SetupWizard::classPreset($school->institution_type),
            'subjectPreset' => SetupWizard::subjectPreset($school->institution_type),
            'existing' => [
                'classes' => SchoolClass::query()->orderBy('level_order')->pluck('name'),
                'subjects' => Subject::query()->orderBy('name')->pluck('name'),
            ],
        ]);
    }

    public function profile(Request $request): RedirectResponse
    {
        $this->authorize('settings.manage');

        $this->wizard->saveProfile($request->validate([
            'name' => ['required', 'string', 'max:190'],
            'name_bn' => ['nullable', 'string', 'max:190'],
            'institution_type' => ['required', Rule::in(['school', 'college', 'kindergarten', 'madrasa', 'combined', 'other'])],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:1000'],
            'district' => ['nullable', 'string', 'max:100'],
            'division' => ['nullable', 'string', 'max:100'],
            'locale' => ['required', Rule::in(['bn', 'en'])],
        ]));

        return back();
    }

    public function session(Request $request): RedirectResponse
    {
        $this->authorize('settings.manage');

        $this->wizard->saveSession($request->validate([
            'name' => ['required', 'string', 'max:100'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
        ]));

        return back();
    }

    public function classes(Request $request): RedirectResponse
    {
        $this->authorize('settings.manage');

        $data = $request->validate([
            'stage' => ['required', Rule::in(['early_years', 'primary', 'secondary', 'higher_secondary', 'college', 'madrasa', 'other'])],
            'classes' => ['required', 'array', 'min:1', 'max:40'],
            'classes.*.name' => ['required', 'string', 'max:100', 'distinct'],
            'classes.*.name_bn' => ['nullable', 'string', 'max:100'],
            'classes.*.sections' => ['array', 'max:12'],
            'classes.*.sections.*' => ['string', 'max:80'],
        ]);

        $this->wizard->saveClasses($data['classes'], $data['stage']);

        return back();
    }

    public function subjects(Request $request): RedirectResponse
    {
        $this->authorize('settings.manage');

        $data = $request->validate([
            'subjects' => ['required', 'array', 'min:1', 'max:60'],
            'subjects.*.name' => ['required', 'string', 'max:150', 'distinct'],
            'subjects.*.name_bn' => ['nullable', 'string', 'max:150'],
            'subjects.*.code' => ['nullable', 'string', 'max:40', 'alpha_dash'],
        ]);

        $this->wizard->saveSubjects($data['subjects']);

        return back();
    }

    public function finish(): RedirectResponse
    {
        $this->authorize('settings.manage');
        $this->wizard->finish();

        return to_route('dashboard');
    }
}
