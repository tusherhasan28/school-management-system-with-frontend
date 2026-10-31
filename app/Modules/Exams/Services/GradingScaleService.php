<?php

namespace App\Modules\Exams\Services;

use App\Modules\Exams\Grading\GradingConfig;
use App\Modules\Exams\Grading\GradingPresets;
use App\Modules\Exams\Grading\GradingRules;
use App\Modules\Exams\Models\Examination;
use App\Modules\Exams\Models\GradingScale;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Grading scales are edited freely until a published result has used them.
 * After that they are locked: the school duplicates the scale and edits the copy,
 * so published results keep pointing at the configuration they were made with.
 */
class GradingScaleService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function fromPreset(string $preset, ?string $name = null): GradingScale
    {
        $p = GradingPresets::all()[$preset] ?? throw ValidationException::withMessages(['preset' => __('exams.preset_unknown')]);

        return $this->create([
            'name' => $name ?: $this->uniqueName($p['name']),
            'description' => $p['description'],
            'rules' => $p['rules'],
            'items' => $p['items'],
        ]);
    }

    /** @param  array<string, mixed>  $data  name, description, rules, items */
    public function create(array $data): GradingScale
    {
        $this->assertBands($data['items']);

        return DB::transaction(function () use ($data) {
            $scale = GradingScale::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'rules' => GradingRules::normalize($data['rules'] ?? []),
                'is_default' => ! GradingScale::query()->exists(),
            ]);
            $this->replaceItems($scale, $data['items']);
            $this->audit->tenant('grading_scale.created', $scale, [], ['name' => $scale->name]);

            return $scale;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(GradingScale $scale, array $data): GradingScale
    {
        if ($scale->isLocked()) {
            throw ValidationException::withMessages(['scale' => __('exams.scale_locked')]);
        }

        $this->assertBands($data['items']);

        return DB::transaction(function () use ($scale, $data) {
            $old = ['rules' => $scale->rules, 'items' => $scale->items()->get(['grade', 'min_percentage', 'grade_point'])->toArray()];
            $scale->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'rules' => GradingRules::normalize($data['rules'] ?? []),
            ]);
            $this->replaceItems($scale, $data['items']);
            $this->audit->tenant('grading_scale.updated', $scale, $old, ['rules' => $scale->rules, 'items' => $data['items']]);

            return $scale->refresh();
        });
    }

    public function duplicate(GradingScale $scale): GradingScale
    {
        return $this->create([
            'name' => $this->uniqueName($scale->name),
            'description' => $scale->description,
            'rules' => $scale->rules,
            'items' => $scale->items->map->only(['grade', 'min_percentage', 'max_percentage', 'grade_point', 'is_fail', 'remarks', 'remarks_bn'])->all(),
        ]);
    }

    public function makeDefault(GradingScale $scale): void
    {
        DB::transaction(function () use ($scale) {
            GradingScale::query()->whereKeyNot($scale->id)->update(['is_default' => false]);
            $scale->forceFill(['is_default' => true])->save();
        });
    }

    public function delete(GradingScale $scale): void
    {
        if ($scale->isLocked() || Examination::query()->where('grading_scale_id', $scale->id)->exists()) {
            throw ValidationException::withMessages(['scale' => __('exams.scale_in_use')]);
        }

        DB::transaction(function () use ($scale) {
            $scale->items()->delete();
            $scale->delete();
            $this->audit->tenant('grading_scale.deleted', null, ['id' => $scale->id, 'name' => $scale->name]);
        });
    }

    /** @param  list<array<string, mixed>>  $items */
    private function assertBands(array $items): void
    {
        $errors = GradingConfig::bandErrors($items);

        if ($errors !== []) {
            throw ValidationException::withMessages(['items' => array_map(fn ($e) => __($e), $errors)]);
        }
    }

    /** @param  list<array<string, mixed>>  $items */
    private function replaceItems(GradingScale $scale, array $items): void
    {
        $scale->items()->delete();

        foreach ($items as $item) {
            $scale->items()->create([
                'grade' => trim((string) $item['grade']),
                'min_percentage' => $item['min_percentage'],
                'max_percentage' => $item['max_percentage'],
                'grade_point' => $item['grade_point'],
                'is_fail' => (bool) ($item['is_fail'] ?? false),
                'remarks' => $item['remarks'] ?? null,
                'remarks_bn' => $item['remarks_bn'] ?? null,
            ]);
        }

        $scale->unsetRelation('items');
    }

    private function uniqueName(string $base): string
    {
        $name = $base;
        $n = 2;

        while (GradingScale::query()->where('name', $name)->exists()) {
            $name = "{$base} ({$n})";
            $n++;
        }

        return $name;
    }
}
