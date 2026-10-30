<?php

namespace App\Modules\Academics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academics\Models\AcademicSession;
use App\Modules\Academics\Models\ClassSubject;
use App\Modules\Academics\Models\SchoolClass;
use App\Modules\Academics\Models\Section;
use App\Modules\Academics\Models\Subject;
use App\Modules\Academics\Services\AcademicSessionService;
use App\Modules\People\Models\StudentEnrollment;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sessions, classes, sections and subjects. Small CRUD surfaces share one
 * controller; business rules (single current session, delete guards) live in
 * the service or are checked here before writes.
 */
class AcademicsController extends Controller
{
    public function __construct(
        private readonly AcademicSessionService $sessions,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', SchoolClass::class);

        $enrolled = StudentEnrollment::query()
            ->whereIn('academic_session_id', AcademicSession::query()->where('is_current', true)->select('id'))
            ->where('status', 'enrolled')
            ->selectRaw('class_id, section_id, COUNT(*) as total')
            ->groupBy('class_id', 'section_id')
            ->get();

        return Inertia::render('app/academics/index', [
            'sessions' => AcademicSession::query()->orderByDesc('start_date')->get()->map(fn ($s) => [
                'id' => $s->id, 'name' => $s->name, 'start_date' => $s->start_date->toDateString(),
                'end_date' => $s->end_date->toDateString(), 'is_current' => $s->is_current, 'status' => $s->status,
            ]),
            'classes' => SchoolClass::query()->with('sections')->orderBy('level_order')->get()->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'name_bn' => $c->name_bn, 'code' => $c->code,
                'level_order' => $c->level_order, 'education_stage' => $c->education_stage, 'is_active' => $c->is_active,
                'students' => (int) $enrolled->where('class_id', $c->id)->sum('total'),
                'sections' => $c->sections->sortBy('name')->values()->map(fn ($s) => [
                    'id' => $s->id, 'name' => $s->name, 'capacity' => $s->capacity, 'room' => $s->room,
                    'students' => (int) $enrolled->where('section_id', $s->id)->sum('total'),
                ]),
            ]),
            'subjects' => Subject::query()->orderBy('name')->get(['id', 'name', 'name_bn', 'code', 'subject_type', 'is_active']),
            'can' => ['manage' => $request->user()->can('create', SchoolClass::class)],
        ]);
    }

    // ---- Sessions ---------------------------------------------------------

    public function storeSession(Request $request): RedirectResponse
    {
        $this->authorize('create', AcademicSession::class);
        $data = $this->validateSession($request);
        $session = $this->sessions->create($data);

        if ($request->boolean('make_current') || ! AcademicSession::query()->where('is_current', true)->exists()) {
            $this->sessions->makeCurrent($session);
        }

        return $this->saved();
    }

    public function updateSession(Request $request, AcademicSession $session): RedirectResponse
    {
        $this->authorize('update', $session);
        $this->sessions->update($session, $this->validateSession($request, $session));

        return $this->saved();
    }

    public function makeCurrent(AcademicSession $session): RedirectResponse
    {
        $this->authorize('update', $session);
        $this->sessions->makeCurrent($session);

        return $this->saved();
    }

    // ---- Classes ----------------------------------------------------------

    public function storeClass(Request $request): RedirectResponse
    {
        $this->authorize('create', SchoolClass::class);
        $class = SchoolClass::create($this->validateClass($request));
        $this->audit->tenant('class.created', $class, new: $class->only(['name', 'level_order']));

        return $this->saved();
    }

    public function updateClass(Request $request, SchoolClass $class): RedirectResponse
    {
        $this->authorize('update', $class);
        $data = $this->validateClass($request, $class);
        $old = $class->only(array_keys($data));
        $class->update($data);
        $this->audit->tenant('class.updated', $class, $old, $data);

        return $this->saved();
    }

    public function destroyClass(SchoolClass $class): RedirectResponse
    {
        $this->authorize('delete', $class);

        if (StudentEnrollment::query()->where('class_id', $class->id)->exists()
            || ClassSubject::query()->where('class_id', $class->id)->exists()) {
            throw ValidationException::withMessages(['class' => __('people.class_in_use')]);
        }

        $this->audit->tenant('class.deleted', $class, $class->only(['name']));
        Section::query()->where('class_id', $class->id)->delete();
        $class->delete();

        return $this->saved();
    }

    // ---- Sections ---------------------------------------------------------

    public function storeSection(Request $request, SchoolClass $class): RedirectResponse
    {
        $this->authorize('update', $class);
        $section = Section::create(['class_id' => $class->id] + $this->validateSection($request, $class));
        $this->audit->tenant('section.created', $section, new: ['class_id' => $class->id, 'name' => $section->name]);

        return $this->saved();
    }

    public function updateSection(Request $request, Section $section): RedirectResponse
    {
        $this->authorize('update', $section);
        $data = $this->validateSection($request, $section->schoolClass()->firstOrFail(), $section);
        $section->update($data);
        $this->audit->tenant('section.updated', $section, new: $data);

        return $this->saved();
    }

    public function destroySection(Section $section): RedirectResponse
    {
        $this->authorize('delete', $section);

        if (StudentEnrollment::query()->where('section_id', $section->id)->exists()) {
            throw ValidationException::withMessages(['section' => __('people.section_in_use')]);
        }

        $this->audit->tenant('section.deleted', $section, $section->only(['name', 'class_id']));
        $section->delete();

        return $this->saved();
    }

    // ---- Subjects ---------------------------------------------------------

    public function storeSubject(Request $request): RedirectResponse
    {
        $this->authorize('create', Subject::class);
        $subject = Subject::create($this->validateSubject($request));
        $this->audit->tenant('subject.created', $subject, new: $subject->only(['name', 'code']));

        return $this->saved();
    }

    public function updateSubject(Request $request, Subject $subject): RedirectResponse
    {
        $this->authorize('update', $subject);
        $subject->update($this->validateSubject($request, $subject));
        $this->audit->tenant('subject.updated', $subject);

        return $this->saved();
    }

    public function destroySubject(Subject $subject): RedirectResponse
    {
        $this->authorize('delete', $subject);

        if (ClassSubject::query()->where('subject_id', $subject->id)->exists()) {
            throw ValidationException::withMessages(['subject' => __('people.subject_in_use')]);
        }

        $this->audit->tenant('subject.deleted', $subject, $subject->only(['name', 'code']));
        $subject->delete();

        return $this->saved();
    }

    // ---- Validation -------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function validateSession(Request $request, ?AcademicSession $session = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', TenantRule::unique('academic_sessions', 'name')->ignore($session?->id)],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'status' => ['sometimes', Rule::in(['planned', 'active', 'completed', 'archived'])],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateClass(Request $request, ?SchoolClass $class = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', TenantRule::unique('classes', 'name')->ignore($class?->id)],
            'name_bn' => ['nullable', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:40', 'alpha_dash', TenantRule::unique('classes', 'code')->ignore($class?->id)],
            'level_order' => ['required', 'integer', 'between:-100,1000'],
            'education_stage' => ['required', Rule::in(['early_years', 'primary', 'secondary', 'higher_secondary', 'college', 'madrasa', 'other'])],
            'is_active' => ['boolean'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateSection(Request $request, SchoolClass $class, ?Section $section = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80', TenantRule::unique('sections', 'name')->where('class_id', $class->id)->ignore($section?->id)],
            'capacity' => ['nullable', 'integer', 'between:1,1000'],
            'room' => ['nullable', 'string', 'max:80'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateSubject(Request $request, ?Subject $subject = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150', TenantRule::unique('subjects', 'name')->ignore($subject?->id)],
            'name_bn' => ['nullable', 'string', 'max:150'],
            'code' => ['nullable', 'string', 'max:40', 'alpha_dash', TenantRule::unique('subjects', 'code')->ignore($subject?->id)],
            'subject_type' => ['required', Rule::in(['compulsory', 'optional', 'elective', 'practical', 'other'])],
            'is_active' => ['boolean'],
        ]);
    }

    private function saved(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => __('people.saved')]);

        return back();
    }
}
