<?php

namespace App\Modules\Homework\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academics\Models\ClassSubject;
use App\Modules\Academics\Services\AcademicOptions;
use App\Modules\Homework\Models\Homework;
use App\Modules\Homework\Models\HomeworkSubmission;
use App\Modules\Homework\Services\HomeworkService;
use App\Modules\Teaching\Services\TeachingAccess;
use App\Modules\Teaching\Services\TeachingScope;
use App\Support\Tenancy\TenantRule;
use App\Support\Tenancy\TenantStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HomeworkController extends Controller
{
    public function __construct(
        private readonly HomeworkService $homework,
        private readonly TeachingAccess $access,
        private readonly TeachingScope $scope,
    ) {}

    public function index(Request $request, AcademicOptions $options): Response
    {
        $user = $request->user();
        abort_unless($user->can('homework.manage') || $user->can('homework.view_all'), 403);

        $filters = $request->validate(['class' => ['nullable', 'integer'], 'status' => ['nullable', Rule::in(['draft', 'published', 'closed'])]]);
        $sessionId = $options->currentSessionId();

        $items = Homework::query()
            ->with(['schoolClass:id,school_id,name', 'section:id,school_id,name', 'subject:id,school_id,name', 'teacher:id,school_id,full_name'])
            ->withCount(['submissions', 'submissions as done_count' => fn ($q) => $q->whereIn('status', ['submitted', 'late', 'graded'])])
            ->where('academic_session_id', $sessionId)
            ->when(! $user->can('homework.view_all'), fn ($q) => $this->scope->constrain($q, $user, subjectColumn: 'subject_id'))
            ->when($filters['class'] ?? null, fn ($q, $c) => $q->where('class_id', $c))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->latest('assigned_on')->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Homework $h) => [
                'id' => $h->id, 'title' => $h->title, 'status' => $h->status,
                'class' => $h->schoolClass?->name, 'section' => $h->section?->name, 'subject' => $h->subject?->name,
                'teacher' => $h->teacher?->full_name, 'assigned_on' => $h->assigned_on->toDateString(),
                'due_at' => $h->due_at?->toIso8601String(), 'submissions' => $h->submissions_count, 'done' => $h->done_count,
            ]);

        return Inertia::render('app/homework/index', [
            'homework' => $items,
            'filters' => ['class' => $filters['class'] ?? null, 'status' => $filters['status'] ?? ''],
            'classes' => $options->classes(),
            'can' => ['create' => $user->can('homework.manage')],
        ]);
    }

    public function create(Request $request, AcademicOptions $options): Response
    {
        abort_unless($request->user()->can('homework.manage'), 403);

        return Inertia::render('app/homework/form', [
            'homework' => null,
            'options' => $this->options($request, $options),
        ]);
    }

    public function store(Request $request, AcademicOptions $options): RedirectResponse
    {
        abort_unless($request->user()->can('homework.manage'), 403);
        $data = $this->validated($request, $options->currentSessionId());

        $homework = $this->homework->create($data, $request->file('attachment'));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('homework.saved')]);

        return to_route('homework.show', $homework);
    }

    public function show(Request $request, Homework $homework): Response
    {
        abort_unless($this->access->canViewHomework($request->user(), $homework), 404);
        $homework->load(['schoolClass:id,school_id,name', 'section:id,school_id,name', 'subject:id,school_id,name', 'teacher:id,school_id,full_name']);

        $submissions = HomeworkSubmission::query()
            ->with(['student' => fn ($q) => $q->select(['id', 'school_id', 'full_name', 'name_bn', 'admission_no'])])
            ->where('homework_id', $homework->id)
            ->get()
            ->sortBy(fn ($s) => $s->student?->full_name)
            ->values();

        return Inertia::render('app/homework/show', [
            'homework' => [
                ...$homework->only(['id', 'title', 'instructions', 'status', 'attachment_name', 'max_marks', 'class_id', 'section_id', 'subject_id']),
                'class' => $homework->schoolClass?->name, 'section' => $homework->section?->name, 'subject' => $homework->subject?->name,
                'teacher' => $homework->teacher?->full_name, 'assigned_on' => $homework->assigned_on->toDateString(),
                'due_at' => $homework->due_at?->format('Y-m-d\TH:i'),
                'has_attachment' => $homework->attachment_path !== null,
            ],
            'submissions' => $submissions->map(fn (HomeworkSubmission $s) => [
                'student_id' => $s->student_id, 'full_name' => $s->student?->full_name, 'admission_no' => $s->student?->admission_no,
                'status' => $s->status, 'marks' => $s->marks, 'feedback' => $s->feedback,
            ]),
            'can' => ['manage' => $this->access->canManageHomework($request->user(), $homework)],
        ]);
    }

    public function update(Request $request, Homework $homework, AcademicOptions $options): RedirectResponse
    {
        abort_unless($this->access->canManageHomework($request->user(), $homework), 404);
        $data = $this->validated($request, $homework->academic_session_id, $homework);

        $this->homework->update($homework, $data, $request->file('attachment'));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('homework.saved')]);

        return back();
    }

    public function grade(Request $request, Homework $homework): RedirectResponse
    {
        abort_unless($this->access->canManageHomework($request->user(), $homework), 404);

        $data = $request->validate([
            'rows' => ['required', 'array', 'max:300'],
            'rows.*.status' => ['required', Rule::in(['pending', 'submitted', 'late', 'graded', 'missing'])],
            'rows.*.marks' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'rows.*.feedback' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->homework->grade($homework, $data['rows']);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('homework.graded')]);

        return back();
    }

    public function attachment(Request $request, Homework $homework, TenantStorage $storage): StreamedResponse
    {
        abort_unless($this->access->canViewHomework($request->user(), $homework), 404);
        abort_unless($homework->attachment_path && $storage->owns($homework->attachment_path), 404);

        return $storage->disk()->download($homework->attachment_path, $homework->attachment_name ?? 'attachment');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?int $sessionId, ?Homework $existing = null): array
    {
        if ($sessionId === null) {
            throw ValidationException::withMessages(['class_id' => __('admissions.no_session')]);
        }

        $data = $request->validate([
            'class_id' => ['required', 'integer', TenantRule::exists('classes')],
            'section_id' => ['nullable', 'integer', TenantRule::exists('sections')->where('class_id', $request->integer('class_id'))],
            'subject_id' => ['required', 'integer', TenantRule::exists('subjects')],
            'title' => ['required', 'string', 'max:190'],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'assigned_on' => ['required', 'date'],
            'due_at' => ['nullable', 'date', 'after_or_equal:assigned_on'],
            'max_marks' => ['nullable', 'numeric', 'min:1', 'max:1000'],
            'status' => ['required', Rule::in(['draft', 'published', 'closed'])],
            'attachment' => ['nullable', 'file', 'max:10240', 'mimes:pdf,doc,docx,jpg,jpeg,png,xlsx,pptx,txt'],
        ]);
        unset($data['attachment']);

        $classId = (int) $data['class_id'];
        $sectionId = isset($data['section_id']) ? (int) $data['section_id'] : null;
        $subjectId = (int) $data['subject_id'];

        if (! $this->access->canSetHomework($request->user(), $classId, $sectionId, $subjectId, $sessionId)) {
            throw ValidationException::withMessages(['subject_id' => __('homework.not_teaching')]);
        }

        if (! ClassSubject::query()->where('academic_session_id', $sessionId)->where('class_id', $classId)->where('subject_id', $subjectId)->exists()) {
            throw ValidationException::withMessages(['subject_id' => __('teaching.subject_not_in_class')]);
        }

        $data['academic_session_id'] = $sessionId;
        $data['section_id'] = $sectionId;
        // The teacher is the signed-in staff member, never an id from the form.
        $data['staff_member_id'] = $existing?->staff_member_id ?? $this->scope->staffFor($request->user())?->id;

        return $data;
    }

    /**
     * Classes/sections/subjects the user may set homework for.
     *
     * @return list<array<string, mixed>>
     */
    private function options(Request $request, AcademicOptions $options): array
    {
        $sessionId = $options->currentSessionId();
        $user = $request->user();
        $subjects = ClassSubject::query()->with('subject:id,school_id,name')->where('academic_session_id', $sessionId)->get()->groupBy('class_id');

        return collect($options->classes())
            ->map(function ($class) use ($user, $subjects, $sessionId) {
                $classSubjects = ($subjects->get($class['id']) ?? collect())
                    ->filter(fn ($cs) => $this->access->canSetHomework($user, $class['id'], null, $cs->subject_id, $sessionId)
                        || collect($class['sections'])->contains(fn ($s) => $this->access->canSetHomework($user, $class['id'], $s['id'], $cs->subject_id, $sessionId)))
                    ->map(fn ($cs) => ['id' => $cs->subject_id, 'name' => $cs->subject?->name])
                    ->values()->all();

                return $class + ['subjects' => $classSubjects];
            })
            ->filter(fn ($c) => $c['subjects'] !== [])
            ->values()->all();
    }
}
