<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\SchoolRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RegisterSchoolController extends Controller
{
    public function __construct(private readonly SchoolRegistrationService $registrations) {}

    public function create(): Response
    {
        return Inertia::render('marketing/register-school', [
            'domain' => config('tenancy.domain'),
            'submitted' => session('registration_submitted', false),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['requested_slug' => Str::lower((string) $request->input('requested_slug'))]);

        $data = $request->validate([
            'institution_name' => ['required', 'string', 'max:190'],
            'institution_name_bn' => ['nullable', 'string', 'max:190'],
            'requested_slug' => ['required', 'string', 'min:3', 'max:63', 'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])$/'],
            'institution_type' => ['required', Rule::in(['school', 'college', 'kindergarten', 'madrasa', 'combined', 'other'])],
            'contact_name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['required', 'regex:/^(\+?88)?01[3-9]\d{8}$/'],
            'district' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:1000'],
            'approx_students' => ['nullable', 'integer', 'between:1,100000'],
            'website' => ['prohibited'], // honeypot
        ]);

        if (! $this->registrations->slugIsAvailable($data['requested_slug'])) {
            return back()->withErrors(['requested_slug' => __('registration.slug_taken')])->withInput();
        }

        $this->registrations->submit($data);

        return to_route('marketing.register-school')->with('registration_submitted', true);
    }

    public function checkSlug(Request $request): JsonResponse
    {
        $slug = Str::lower((string) $request->query('slug'));

        return response()->json([
            'available' => preg_match('/^[a-z0-9](?:[a-z0-9-]{1,61}[a-z0-9])$/', $slug) === 1 && $this->registrations->slugIsAvailable($slug),
        ]);
    }
}
