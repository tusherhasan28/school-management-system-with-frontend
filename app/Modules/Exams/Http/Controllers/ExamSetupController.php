<?php

namespace App\Modules\Exams\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Exams\Grading\GradingPresets;
use App\Modules\Exams\Grading\GradingRules;
use App\Modules\Exams\Models\Examination;
use App\Modules\Exams\Models\ExamType;
use App\Modules\Exams\Models\GradingScale;
use App\Modules\Exams\Services\GradingScaleService;
use App\Support\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Exam types and grading scales. */
class ExamSetupController extends Controller
{
    public function __construct(private readonly GradingScaleService $scales) {}

    public function index(Request $request): Response
    {
        $this->authorizeManage($request);

        return Inertia::render('app/exams/setup', [
            'types' => ExamType::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'name_bn'])
                ->map(fn ($t) => $t->only(['id', 'name', 'name_bn']) + ['in_use' => Examination::query()->where('exam_type_id', $t->id)->exists()]),
            'scales' => GradingScale::query()->withCount('items')->orderByDesc('is_default')->orderBy('name')->get()
                ->map(fn (GradingScale $s) => [
                    'id' => $s->id, 'name' => $s->name, 'description' => $s->description, 'is_default' => $s->is_default,
                    'locked' => $s->isLocked(), 'items_count' => $s->items_count, 'gpa_method' => $s->rules['gpa_method'] ?? 'none',
                ]),
            'presets' => collect(GradingPresets::all())->map(fn ($p, $key) => ['key' => $key, 'name' => $p['name'], 'description' => $p['description']])->values(),
        ]);
    }

    public function storeType(Request $request): RedirectResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', TenantRule::unique('exam_types', 'name')],
            'name_bn' => ['nullable', 'string', 'max:100'],
        ]);
        ExamType::create($data + ['sort_order' => (int) ExamType::query()->max('sort_order') + 1]);

        return back();
    }

    public function updateType(Request $request, ExamType $type): RedirectResponse
    {
        $this->authorizeManage($request);
        $type->update($request->validate([
            'name' => ['required', 'string', 'max:100', TenantRule::unique('exam_types', 'name')->ignore($type->id)],
            'name_bn' => ['nullable', 'string', 'max:100'],
        ]));

        return back();
    }

    public function destroyType(Request $request, ExamType $type): RedirectResponse
    {
        $this->authorizeManage($request);

        if (Examination::query()->where('exam_type_id', $type->id)->exists()) {
            throw ValidationException::withMessages(['type' => __('exams.type_in_use')]);
        }

        $type->delete();

        return back();
    }

    public function storeScale(Request $request): RedirectResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate(['preset' => ['required', Rule::in(array_keys(GradingPresets::all()))]]);
        $scale = $this->scales->fromPreset($data['preset']);

        return to_route('grading-scales.edit', $scale);
    }

    public function editScale(Request $request, GradingScale $scale): Response
    {
        $this->authorizeManage($request);
        $scale->load('items');

        return Inertia::render('app/exams/grading-scale', [
            'scale' => [
                'id' => $scale->id, 'name' => $scale->name, 'description' => $scale->description, 'is_default' => $scale->is_default,
                'locked' => $scale->isLocked(), 'rules' => GradingRules::normalize($scale->rules ?? []),
                'items' => $scale->items->map->only(['grade', 'min_percentage', 'max_percentage', 'grade_point', 'is_fail', 'remarks', 'remarks_bn'])->values(),
                'exams' => Examination::query()->where('grading_scale_id', $scale->id)->orderByDesc('id')->limit(10)->get(['id', 'name', 'status']),
            ],
            'options' => GradingRules::OPTIONS,
        ]);
    }

    public function updateScale(Request $request, GradingScale $scale): RedirectResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', TenantRule::unique('grading_scales', 'name')->ignore($scale->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:2', 'max:20'],
            'items.*.grade' => ['required', 'string', 'max:10'],
            'items.*.min_percentage' => ['required', 'numeric', 'between:0,100', 'decimal:0,2'],
            'items.*.max_percentage' => ['required', 'numeric', 'between:0,100', 'decimal:0,2'],
            'items.*.grade_point' => ['required', 'numeric', 'between:0,99.99', 'decimal:0,2'],
            'items.*.is_fail' => ['boolean'],
            'items.*.remarks' => ['nullable', 'string', 'max:100'],
            'items.*.remarks_bn' => ['nullable', 'string', 'max:100'],
        ] + GradingRules::validation());

        $data['items'] = array_map(fn ($i) => array_map(fn ($v) => is_numeric($v) && ! is_bool($v) ? (string) $v : $v, $i), $data['items']);
        $this->scales->update($scale, $data);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('exams.scale_saved')]);

        return back();
    }

    public function duplicateScale(Request $request, GradingScale $scale): RedirectResponse
    {
        $this->authorizeManage($request);

        return to_route('grading-scales.edit', $this->scales->duplicate($scale->load('items')));
    }

    public function defaultScale(Request $request, GradingScale $scale): RedirectResponse
    {
        $this->authorizeManage($request);
        $this->scales->makeDefault($scale);

        return back();
    }

    public function destroyScale(Request $request, GradingScale $scale): RedirectResponse
    {
        $this->authorizeManage($request);
        $this->scales->delete($scale);

        return to_route('exams.setup');
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()->can('exams.manage'), 403);
    }
}
