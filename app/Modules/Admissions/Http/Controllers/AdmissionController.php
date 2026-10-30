<?php

namespace App\Modules\Admissions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academics\Services\AcademicOptions;
use App\Modules\Admissions\Models\AdmissionApplication;
use App\Modules\Admissions\Services\AdmissionService;
use App\Support\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AdmissionController extends Controller
{
    public function __construct(private readonly AdmissionService $admissions) {}

    public function index(Request $request, AcademicOptions $options): Response
    {
        $this->authorize('viewAny', AdmissionApplication::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['submitted', 'under_review', 'approved', 'rejected', 'enrolled'])],
            'class' => ['nullable', 'integer'],
        ]);

        $applications = AdmissionApplication::query()
            ->with('appliedClass:id,name,school_id')
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('applicant_name', 'like', "%{$s}%")->orWhere('application_no', 'like', "%{$s}%")->orWhere('guardian_phone', 'like', "%{$s}%")))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['class'] ?? null, fn ($q, $c) => $q->where('applied_class_id', $c))
            ->latest('submitted_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (AdmissionApplication $a) => [
                'id' => $a->id, 'application_no' => $a->application_no, 'applicant_name' => $a->applicant_name,
                'class' => $a->appliedClass?->name, 'guardian_phone' => $a->guardian_phone, 'status' => $a->status,
                'submitted_at' => $a->submitted_at?->toIso8601String(),
            ]);

        $counts = AdmissionApplication::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return Inertia::render('app/admissions/index', [
            'applications' => $applications,
            'filters' => ['search' => $filters['search'] ?? '', 'status' => $filters['status'] ?? '', 'class' => $filters['class'] ?? null],
            'counts' => $counts,
            'classes' => $options->classes(false),
        ]);
    }

    public function show(Request $request, AdmissionApplication $admission, AcademicOptions $options): Response
    {
        $this->authorize('view', $admission);
        $admission->load(['appliedClass:id,name,name_bn,school_id', 'academicSession:id,name,school_id', 'reviewer:id,name,school_id']);

        return Inertia::render('app/admissions/show', [
            'application' => [
                ...$admission->only(['id', 'application_no', 'applicant_name', 'applicant_name_bn', 'gender', 'guardian_name', 'guardian_relationship', 'guardian_phone', 'guardian_email', 'address', 'previous_school', 'status', 'review_note', 'student_id', 'applied_class_id', 'academic_session_id']),
                'date_of_birth' => $admission->date_of_birth?->toDateString(),
                'extra' => $admission->application_data ?? [],
                'class' => $admission->appliedClass?->name,
                'session' => $admission->academicSession?->name,
                'reviewer' => $admission->reviewer?->name,
                'reviewed_at' => $admission->reviewed_at?->toIso8601String(),
                'submitted_at' => $admission->submitted_at?->toIso8601String(),
            ],
            'classes' => $options->classes(),
            'sessions' => $options->sessions(),
            'can' => ['convert' => $request->user()->can('convert', $admission)],
        ]);
    }

    public function review(Request $request, AdmissionApplication $admission): RedirectResponse
    {
        $this->authorize('update', $admission);

        $data = $request->validate([
            'status' => ['required', Rule::in(['under_review', 'approved', 'rejected'])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->admissions->review($admission, $data['status'], $data['note'] ?? null);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('admissions.updated')]);

        return back();
    }

    public function convert(Request $request, AdmissionApplication $admission): RedirectResponse
    {
        $this->authorize('convert', $admission);

        $data = $request->validate([
            'academic_session_id' => ['nullable', 'integer', TenantRule::exists('academic_sessions')],
            'class_id' => ['required', 'integer', TenantRule::exists('classes')],
            'section_id' => ['nullable', 'integer', TenantRule::exists('sections')->where('class_id', $request->integer('class_id'))],
            'roll_no' => ['nullable', 'string', 'max:40'],
        ]);

        $student = $this->admissions->convert($admission, $data);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('admissions.converted')]);

        return to_route('students.show', $student);
    }
}
