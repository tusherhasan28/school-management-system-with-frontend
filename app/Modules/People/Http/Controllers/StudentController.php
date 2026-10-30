<?php

namespace App\Modules\People\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academics\Services\AcademicOptions;
use App\Modules\People\Http\Requests\StudentRequest;
use App\Modules\People\Models\Guardian;
use App\Modules\People\Models\Student;
use App\Modules\People\Models\StudentEnrollment;
use App\Modules\People\Services\EnrollmentLifecycle;
use App\Modules\People\Services\GuardianService;
use App\Modules\People\Services\StudentService;
use App\Support\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StudentController extends Controller
{
    public function __construct(
        private readonly StudentService $students,
        private readonly AcademicOptions $options,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Student::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'session' => ['nullable', 'integer'],
            'class' => ['nullable', 'integer'],
            'section' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'graduated', 'transferred', 'withdrawn'])],
        ]);

        $sessionId = $filters['session'] ?? $this->options->currentSessionId();

        // Enrollment for the selected session, joined as a subquery-free relation load.
        $students = Student::query()
            ->with(['enrollments' => fn ($q) => $q->where('academic_session_id', $sessionId)->with(['schoolClass:id,name,name_bn,school_id', 'section:id,name,school_id'])])
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('full_name', 'like', "%{$s}%")->orWhere('name_bn', 'like', "%{$s}%")->orWhere('admission_no', 'like', "%{$s}%")))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['class'] ?? null, fn ($q, $c) => $q->whereHas('enrollments', fn ($q) => $q
                ->where('academic_session_id', $sessionId)->where('class_id', $c)
                ->when($filters['section'] ?? null, fn ($q, $sec) => $q->where('section_id', $sec))))
            ->orderBy('full_name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Student $s) => [
                'id' => $s->id,
                'admission_no' => $s->admission_no,
                'full_name' => $s->full_name,
                'name_bn' => $s->name_bn,
                'gender' => $s->gender,
                'status' => $s->status,
                'class' => $s->enrollments->first()?->schoolClass?->name,
                'section' => $s->enrollments->first()?->section?->name,
                'roll_no' => $s->enrollments->first()?->roll_no,
            ]);

        return Inertia::render('app/students/index', [
            'students' => $students,
            'filters' => ['search' => $filters['search'] ?? '', 'session' => $sessionId, 'class' => $filters['class'] ?? null, 'section' => $filters['section'] ?? null, 'status' => $filters['status'] ?? ''],
            'sessions' => $this->options->sessions(),
            'classes' => $this->options->classes(),
            'can' => [
                'create' => $request->user()->can('create', Student::class),
                'promote' => $request->user()->can('promote', Student::class),
                'import' => $request->user()->can('imports.manage'),
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Student::class);

        return Inertia::render('app/students/form', [
            'student' => null,
            'sessions' => $this->options->sessions(),
            'classes' => $this->options->classes(),
            'currentSessionId' => $this->options->currentSessionId(),
        ]);
    }

    public function store(StudentRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $student = $this->students->create(
            Arr::except($data, ['academic_session_id', 'class_id', 'section_id', 'roll_no', 'guardians']),
            $data['guardians'],
            Arr::only($data, ['academic_session_id', 'class_id', 'section_id', 'roll_no']),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('people.student_created')]);

        return to_route('students.show', $student);
    }

    public function show(Request $request, Student $student): Response
    {
        $this->authorize('view', $student);

        $student->load([
            'guardians:id,full_name,name_bn,phone,email,occupation,school_id',
            'enrollments' => fn ($q) => $q->orderByDesc('enrolled_at')->orderByDesc('id')
                ->with(['academicSession:id,name,school_id', 'schoolClass:id,name,name_bn,school_id', 'section:id,name,school_id']),
        ]);

        $user = $request->user();

        return Inertia::render('app/students/show', [
            'student' => [
                ...$student->only(['id', 'admission_no', 'full_name', 'name_bn', 'gender', 'blood_group', 'religion', 'nationality', 'present_address', 'permanent_address', 'status']),
                'date_of_birth' => $student->date_of_birth?->toDateString(),
                'admission_date' => $student->admission_date?->toDateString(),
                // Masked unless the viewer may edit the record.
                'birth_registration_no' => $user->can('viewSensitive', $student)
                    ? $student->birth_registration_no
                    : ($student->birth_registration_no ? '••••'.substr((string) $student->birth_registration_no, -4) : null),
                'guardians' => $student->guardians->map(fn (Guardian $g) => [
                    'id' => $g->id, 'full_name' => $g->full_name, 'name_bn' => $g->name_bn, 'phone' => $g->phone,
                    'email' => $g->email, 'occupation' => $g->occupation,
                    'relationship' => $g->pivot->relationship, 'is_primary' => (bool) $g->pivot->is_primary,
                ]),
                'enrollments' => $student->enrollments->map(fn (StudentEnrollment $e) => [
                    'id' => $e->id, 'session' => $e->academicSession?->name, 'class' => $e->schoolClass?->name,
                    'section' => $e->section?->name, 'roll_no' => $e->roll_no, 'status' => $e->status,
                    'enrolled_at' => $e->enrolled_at?->toDateString(), 'ended_at' => $e->ended_at?->toDateString(),
                    'end_reason' => $e->end_reason,
                ]),
            ],
            'can' => [
                'update' => $user->can('update', $student),
                'changeStatus' => $user->can('changeStatus', $student),
                'manageGuardians' => $user->can('update', $student) && $user->can('guardians.manage'),
            ],
        ]);
    }

    public function edit(Student $student): Response
    {
        $this->authorize('update', $student);

        return Inertia::render('app/students/form', [
            'student' => [
                ...$student->only(['id', 'admission_no', 'full_name', 'name_bn', 'gender', 'blood_group', 'religion', 'nationality', 'present_address', 'permanent_address']),
                'date_of_birth' => $student->date_of_birth?->toDateString(),
                'admission_date' => $student->admission_date?->toDateString(),
                'has_birth_registration_no' => $student->birth_registration_no !== null,
            ],
            'sessions' => [],
            'classes' => [],
            'currentSessionId' => null,
        ]);
    }

    public function update(StudentRequest $request, Student $student): RedirectResponse
    {
        $this->students->update($student, $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('people.saved')]);

        return to_route('students.show', $student);
    }

    public function linkGuardian(Request $request, Student $student, GuardianService $guardians): RedirectResponse
    {
        $this->authorize('update', $student);
        $this->authorize('guardians.manage');

        $data = $request->validate([
            'guardian_id' => ['nullable', 'integer', TenantRule::exists('guardians')],
            'full_name' => ['required_without:guardian_id', 'nullable', 'string', 'max:160'],
            'phone' => ['required_without:guardian_id', 'nullable', 'regex:/^(\+?88)?01[3-9]\d{8}$/'],
            'email' => ['nullable', 'email', 'max:190'],
            'relationship' => ['required', Rule::in(['father', 'mother', 'guardian', 'brother', 'sister', 'uncle', 'aunt', 'grandparent', 'other'])],
            'is_primary' => ['boolean'],
        ]);

        $guardian = isset($data['guardian_id'])
            ? Guardian::query()->findOrFail($data['guardian_id'])
            : $guardians->findOrCreate(Arr::only($data, ['full_name', 'phone', 'email']));

        $guardians->link($student, $guardian, $data['relationship'], (bool) ($data['is_primary'] ?? false));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('people.saved')]);

        return back();
    }

    public function unlinkGuardian(Student $student, Guardian $guardian, GuardianService $guardians): RedirectResponse
    {
        $this->authorize('update', $student);
        $this->authorize('guardians.manage');
        $guardians->unlink($student, $guardian);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('people.saved')]);

        return back();
    }

    public function changeStatus(Request $request, Student $student, EnrollmentLifecycle $lifecycle): RedirectResponse
    {
        $this->authorize('changeStatus', $student);

        $data = $request->validate([
            'outcome' => ['required', Rule::in(['transferred', 'withdrawn', 'graduated'])],
            'reason' => ['nullable', 'string', 'max:255', 'required_if:outcome,transferred,withdrawn'],
            'date' => ['nullable', 'date'],
        ]);

        $lifecycle->end($student, $data['outcome'], $data['reason'] ?? null, $data['date'] ?? null);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('people.saved')]);

        return back();
    }
}
