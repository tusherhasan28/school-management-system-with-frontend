<?php

namespace App\Modules\Exams\Grading;

use App\Modules\Exams\Models\GradingScale;

/**
 * Immutable grading configuration handed to the engine. Built either from a
 * live GradingScale or from the snapshot stored with a calculation, so an old
 * result can be recalculated exactly as it was.
 */
final class GradingConfig
{
    /** @var list<array{grade: string, min: int, max: int, gp: int, is_fail: bool, remarks: ?string, remarks_bn: ?string}> sorted by min desc */
    public readonly array $bands;

    /** @var array<string, mixed> */
    public readonly array $rules;

    /**
     * @param  list<array<string, mixed>>  $bands  grade, min_percentage, max_percentage, grade_point, is_fail, remarks, remarks_bn
     * @param  array<string, mixed>  $rules
     */
    public function __construct(array $bands, array $rules, public readonly ?string $name = null)
    {
        $parsed = array_map(fn (array $b) => [
            'grade' => (string) $b['grade'],
            'min' => Hundredths::parse((string) $b['min_percentage']),
            'max' => Hundredths::parse((string) $b['max_percentage']),
            'gp' => Hundredths::parse((string) $b['grade_point']),
            'is_fail' => (bool) ($b['is_fail'] ?? false),
            'remarks' => $b['remarks'] ?? null,
            'remarks_bn' => $b['remarks_bn'] ?? null,
        ], array_values($bands));

        usort($parsed, fn ($a, $b) => [$b['min'], $b['gp']] <=> [$a['min'], $a['gp']]);

        $this->bands = $parsed;
        $this->rules = GradingRules::normalize($rules);
    }

    public static function fromScale(GradingScale $scale): self
    {
        $scale->loadMissing('items');

        return new self(
            $scale->items->map(fn ($i) => $i->only(['grade', 'min_percentage', 'max_percentage', 'grade_point', 'is_fail', 'remarks', 'remarks_bn']))->all(),
            $scale->rules ?? [],
            $scale->name,
        );
    }

    /** @param  array<string, mixed>  $snapshot */
    public static function fromSnapshot(array $snapshot): self
    {
        return new self($snapshot['bands'], $snapshot['rules'], $snapshot['name'] ?? null);
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return [
            'name' => $this->name,
            'bands' => array_map(fn ($b) => [
                'grade' => $b['grade'],
                'min_percentage' => Hundredths::format($b['min']),
                'max_percentage' => Hundredths::format($b['max']),
                'grade_point' => Hundredths::format($b['gp']),
                'is_fail' => $b['is_fail'],
                'remarks' => $b['remarks'],
                'remarks_bn' => $b['remarks_bn'],
            ], $this->bands),
            'rules' => $this->rules,
        ];
    }

    /** Band for a percentage (hundredths): the highest band whose minimum it reaches. */
    public function bandFor(int $percentage): array
    {
        foreach ($this->bands as $band) {
            if ($band['min'] <= $percentage) {
                return $band;
            }
        }

        return $this->failBand();
    }

    /** The highest passing band whose grade point the GPA reaches. */
    public function bandForGpa(int $gpa): array
    {
        $passing = array_filter($this->bands, fn ($b) => ! $b['is_fail']);
        usort($passing, fn ($a, $b) => $b['gp'] <=> $a['gp']);

        foreach ($passing as $band) {
            if ($band['gp'] <= $gpa) {
                return $band;
            }
        }

        return $this->failBand();
    }

    public function failBand(): array
    {
        $fails = array_values(array_filter($this->bands, fn ($b) => $b['is_fail']));
        $pool = $fails ?: $this->bands;
        usort($pool, fn ($a, $b) => [$a['gp'], $a['min']] <=> [$b['gp'], $b['min']]);

        return $pool[0];
    }

    /**
     * Problems with a set of bands, as translation keys with replacements.
     * Bands must cover 0–100 without gaps or overlaps and include a fail band.
     *
     * @param  list<array<string, mixed>>  $bands
     * @return list<string>
     */
    public static function bandErrors(array $bands): array
    {
        if (count($bands) < 2) {
            return ['exams.bands_min_two'];
        }

        $errors = [];
        $parsed = [];

        foreach ($bands as $b) {
            $min = Hundredths::parse((string) $b['min_percentage']);
            $max = Hundredths::parse((string) $b['max_percentage']);

            if ($min > $max || $min < 0 || $max > 10000) {
                $errors[] = 'exams.band_range_invalid';
            }

            $parsed[] = ['min' => $min, 'max' => $max, 'grade' => strtoupper(trim((string) $b['grade']))];
        }

        if (count(array_unique(array_column($parsed, 'grade'))) !== count($parsed)) {
            $errors[] = 'exams.band_grade_duplicate';
        }

        if (! collect($bands)->contains(fn ($b) => (bool) ($b['is_fail'] ?? false))) {
            $errors[] = 'exams.band_fail_missing';
        }

        usort($parsed, fn ($a, $b) => $a['min'] <=> $b['min']);

        if ($parsed[0]['min'] !== 0 || end($parsed)['max'] !== 10000) {
            $errors[] = 'exams.band_coverage';
        }

        for ($i = 1; $i < count($parsed); $i++) {
            $gap = $parsed[$i]['min'] - $parsed[$i - 1]['max'];

            if ($gap < 0 || $gap > 1) {
                $errors[] = 'exams.band_coverage';
                break;
            }
        }

        return array_values(array_unique($errors));
    }
}
