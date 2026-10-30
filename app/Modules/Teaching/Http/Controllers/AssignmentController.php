<?php

namespace App\Modules\Teaching\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academics\Models\Subject;
use App\Modules\Academics\Services\AcademicOptions;
use App\Modules\People\Models\StaffMember;
use App\Modules\Teaching\Models\TeacherAssignment;
use App\Modules\Teaching\Services\AssignmentService;
use App\Support\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AssignmentController extends Controller
{
    public function index(Request $request, AcademicOptions $options): Response
    {
        $this->authorize('assignments.manage');

        $filters = $request->validate([
            'session' => ['nullable', 'integer'],
            'teacher' => ['nullable', 'integer'],
            'class' => ['nullable', 'integer'],
        ]);
        $sessionId = $filters['session'] ?? $options->currentSessionId();

        $assignments = TeacherAssignment::query()
            ->with(['teacher:id,school_id,full_name,name_bn', 'schoolClass:id,school_id,name,level_order', 'section:id,school_id,name', 'subject:id,school_id,name'])
            ->where('academic_session_id', $sessionId)
            ->when($filters['teacher'] ?? null, fn ($q, $t) => $q->where('staff_member_id', $t))
            ->when($filters['class'] ?? null, fn ($q, $c) => $q->where('class_id', $c))
            ->get()
            ->sortBy([
                fn ($a, $b) => ($a->schoolClass?->level_order <=> $b->schoolClass?->level_order),
                fn ($a, $b) => strnatcasecmp((string) $a->section?->name, (string) $b->section?->name),
                fn ($a, $b) => ($a->assignment_type === 'class_teacher' ? 0 : 1) <=> ($b->assignment_type === 'class_teacher' ? 0 : 1),
            ])
            ->values()
            ->map(fn (TeacherAssignment $a) => [
                'id' => $a->id,
                'teacher' => $a->teacher?->full_name,
                'teacher_id' => $a->staff_member_id,
                'class' => $a->schoolClass?->name,
                'section' => $a->section?->name,
                'subject' => $a->subject?->name,
                'assignment_type' => $a->assignment_type,
            ]);

        return Inertia::render('app/teaching/assignments', [
            'assignments' => $assignments,
            'filters' => ['session' => $sessionId, 'teacher' => $filters['teacher'] ?? null, 'class' => $filters['class'] ?? null],
            'sessions' => $options->sessions(),
            'classes' => $options->classes(),
            'subjects' => Subject::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'teachers' => StaffMember::query()->where('staff_type', 'teacher')->where('status', 'active')->orderBy('full_name')->get(['id', 'full_name', 'name_bn']),
        ]);
    }

    public function store(Request $request, AssignmentService $service): RedirectResponse
    {
        $this->authorize('assignments.manage');

        $data = $request->validate([
            'academic_session_id' => ['required', 'integer', TenantRule::exists('academic_sessions')],
            'staff_member_id' => ['required', 'integer', TenantRule::exists('staff_members')],
            'class_id' => ['required', 'integer', TenantRule::exists('classes')],
            'section_id' => ['nullable', 'integer', TenantRule::exists('sections')->where('class_id', $request->integer('class_id'))],
            'subject_id' => ['nullable', 'integer', TenantRule::exists('subjects')],
            'assignment_type' => ['required', Rule::in(['class_teacher', 'subject_teacher'])],
        ]);

        $service->assign([
            'academic_session_id' => (int) $data['academic_session_id'],
            'staff_member_id' => (int) $data['staff_member_id'],
            'class_id' => (int) $data['class_id'],
            'section_id' => isset($data['section_id']) ? (int) $data['section_id'] : null,
            'subject_id' => isset($data['subject_id']) ? (int) $data['subject_id'] : null,
            'assignment_type' => $data['assignment_type'],
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('teaching.saved')]);

        return back();
    }

    public function destroy(TeacherAssignment $assignment, AssignmentService $service): RedirectResponse
    {
        $this->authorize('assignments.manage');
        $service->remove($assignment);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('teaching.deleted')]);

        return back();
    }
}
