<?php

namespace App\Modules\Academics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academics\Models\AcademicSession;
use App\Modules\Academics\Models\ClassSubject;
use App\Modules\Academics\Models\SchoolClass;
use App\Modules\Academics\Models\Subject;
use App\Modules\Academics\Services\AcademicOptions;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Subjects taught in a class for a session, with full/pass marks.
 */
class ClassSubjectController extends Controller
{
    public function edit(Request $request, SchoolClass $class, AcademicOptions $options): Response
    {
        $this->authorize('view', $class);

        $sessionId = (int) ($request->integer('session') ?: $options->currentSessionId());
        $session = AcademicSession::query()->find($sessionId);

        $assigned = $session
            ? ClassSubject::query()->where('academic_session_id', $session->id)->where('class_id', $class->id)->get()->keyBy('subject_id')
            : collect();

        return Inertia::render('app/academics/class-subjects', [
            'schoolClass' => $class->only(['id', 'name', 'name_bn']),
            'session' => $session?->only(['id', 'name']),
            'sessions' => $options->sessions(),
            'subjects' => Subject::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'name_bn', 'code'])
                ->map(fn ($s) => [
                    'id' => $s->id, 'name' => $s->name, 'name_bn' => $s->name_bn, 'code' => $s->code,
                    'assigned' => $assigned->has($s->id),
                    'full_marks' => $assigned->get($s->id)?->full_marks ?? '100.00',
                    'pass_marks' => $assigned->get($s->id)?->pass_marks ?? '33.00',
                    'is_optional' => (bool) ($assigned->get($s->id)?->is_optional ?? false),
                ]),
            'can' => ['manage' => $request->user()->can('update', $class)],
        ]);
    }

    public function update(Request $request, SchoolClass $class, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('update', $class);

        $data = $request->validate([
            'academic_session_id' => ['required', 'integer', TenantRule::exists('academic_sessions')],
            'subjects' => ['array'],
            'subjects.*.subject_id' => ['required', 'integer', 'distinct', TenantRule::exists('subjects')],
            'subjects.*.full_marks' => ['required', 'numeric', 'between:1,1000'],
            'subjects.*.pass_marks' => ['required', 'numeric', 'min:0', 'lte:subjects.*.full_marks'],
            'subjects.*.is_optional' => ['boolean'],
        ]);

        DB::transaction(function () use ($data, $class, $audit) {
            $keep = collect($data['subjects'] ?? [])->pluck('subject_id')->all();

            ClassSubject::query()
                ->where('academic_session_id', $data['academic_session_id'])
                ->where('class_id', $class->id)
                ->whereNotIn('subject_id', $keep ?: [0])
                ->delete();

            foreach ($data['subjects'] ?? [] as $i => $row) {
                $mapping = ClassSubject::query()->firstOrNew([
                    'academic_session_id' => $data['academic_session_id'],
                    'class_id' => $class->id,
                    'subject_id' => $row['subject_id'],
                ]);
                $mapping->fill([
                    'full_marks' => $row['full_marks'],
                    'pass_marks' => $row['pass_marks'],
                    'is_optional' => (bool) ($row['is_optional'] ?? false),
                    'sort_order' => $i,
                ])->save();
            }

            $audit->tenant('class.subjects_synced', $class, new: ['session_id' => $data['academic_session_id'], 'subjects' => $keep]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('people.saved')]);

        return back();
    }
}
