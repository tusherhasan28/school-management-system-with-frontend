<?php

namespace App\Modules\Teaching\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academics\Models\ClassSubject;
use App\Modules\Academics\Models\Section;
use App\Modules\Academics\Services\AcademicOptions;
use App\Modules\People\Models\StaffMember;
use App\Modules\Teaching\Models\ClassRoutine;
use App\Modules\Teaching\Services\SchoolCalendar;
use App\Modules\Teaching\Services\TeachingScope;
use App\Modules\Teaching\Services\TimetableService;
use App\Support\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TimetableController extends Controller
{
    public function index(Request $request, AcademicOptions $options, SchoolCalendar $calendar): Response
    {
        $this->authorize('timetable.view');

        $filters = $request->validate(['session' => ['nullable', 'integer'], 'section' => ['nullable', 'integer']]);
        $sessionId = $filters['session'] ?? $options->currentSessionId();
        $section = isset($filters['section']) ? Section::query()->with('schoolClass:id,school_id,name')->find($filters['section']) : null;

        $periods = $section ? $this->periods(ClassRoutine::query()->where('academic_session_id', $sessionId)->where('section_id', $section->id)) : [];

        return Inertia::render('app/teaching/timetable', [
            'filters' => ['session' => $sessionId, 'section' => $section?->id],
            'section' => $section ? ['id' => $section->id, 'name' => $section->name, 'class_id' => $section->class_id, 'class' => $section->schoolClass?->name] : null,
            'periods' => $periods,
            'sessions' => $options->sessions(),
            'classes' => $options->classes(),
            'subjects' => $section ? ClassSubject::query()->with('subject:id,school_id,name')->where('academic_session_id', $sessionId)->where('class_id', $section->class_id)->get()
                ->map(fn ($cs) => ['id' => $cs->subject_id, 'name' => $cs->subject?->name])->values() : [],
            'teachers' => StaffMember::query()->where('staff_type', 'teacher')->where('status', 'active')->orderBy('full_name')->get(['id', 'full_name']),
            'weekend' => $calendar->weekendDays(),
            'can' => ['manage' => $request->user()->can('timetable.manage')],
        ]);
    }

    /** The signed-in teacher's own weekly timetable. */
    public function mine(Request $request, AcademicOptions $options, TeachingScope $scope, SchoolCalendar $calendar): Response
    {
        $staff = $scope->staffFor($request->user());
        abort_if($staff === null, 403);

        return Inertia::render('app/teaching/my-timetable', [
            'periods' => $this->periods(ClassRoutine::query()->where('academic_session_id', $options->currentSessionId())->where('staff_member_id', $staff->id)),
            'weekend' => $calendar->weekendDays(),
            'teacher' => $staff->full_name,
        ]);
    }

    public function store(Request $request, TimetableService $service): RedirectResponse
    {
        $this->authorize('timetable.manage');
        $service->save($this->validated($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('teaching.saved')]);

        return back();
    }

    public function update(Request $request, ClassRoutine $routine, TimetableService $service): RedirectResponse
    {
        $this->authorize('timetable.manage');
        $service->save($this->validated($request), $routine);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('teaching.saved')]);

        return back();
    }

    public function destroy(ClassRoutine $routine, TimetableService $service): RedirectResponse
    {
        $this->authorize('timetable.manage');
        $service->delete($routine);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'academic_session_id' => ['required', 'integer', TenantRule::exists('academic_sessions')],
            'section_id' => ['required', 'integer', TenantRule::exists('sections')],
            'subject_id' => ['required', 'integer', TenantRule::exists('subjects')],
            'staff_member_id' => ['nullable', 'integer', TenantRule::exists('staff_members')],
            'day_of_week' => ['required', 'integer', 'between:1,7'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
            'room' => ['nullable', 'string', 'max:80'],
        ]);

        $data['class_id'] = Section::query()->findOrFail($data['section_id'])->class_id;
        $data['staff_member_id'] = $data['staff_member_id'] ?? null;
        $data['room'] = $data['room'] ?? null;

        return $data;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<ClassRoutine>  $query
     * @return list<array<string, mixed>>
     */
    private function periods($query): array
    {
        return $query
            ->with(['subject:id,school_id,name', 'teacher:id,school_id,full_name', 'schoolClass:id,school_id,name', 'section:id,school_id,name'])
            ->orderBy('day_of_week')->orderBy('starts_at')
            ->get()
            ->map(fn (ClassRoutine $r) => [
                'id' => $r->id, 'day_of_week' => $r->day_of_week,
                'starts_at' => substr($r->starts_at, 0, 5), 'ends_at' => substr($r->ends_at, 0, 5),
                'subject_id' => $r->subject_id, 'subject' => $r->subject?->name,
                'staff_member_id' => $r->staff_member_id, 'teacher' => $r->teacher?->full_name,
                'class' => $r->schoolClass?->name, 'section' => $r->section?->name, 'section_id' => $r->section_id,
                'room' => $r->room,
            ])
            ->all();
    }
}
