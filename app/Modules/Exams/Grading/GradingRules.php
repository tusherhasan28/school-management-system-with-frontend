<?php

namespace App\Modules\Exams\Grading;

/**
 * The configurable rules of a grading scale. Every key has a default so older
 * scales keep working when a new rule is added.
 */
final class GradingRules
{
    public const DEFAULTS = [
        // average: every counted subject's grade point is averaged.
        // optional_bonus: compulsory subjects are averaged and the optional
        //   ("4th") subject only adds the points above optional_bonus_above.
        // none: no GPA; the overall grade comes from the overall percentage.
        'gpa_method' => 'optional_bonus',
        'optional_bonus_above' => '2.00',
        'gpa_cap' => '5.00',            // null = no cap
        'gpa_decimals' => 2,            // 0–2
        'gpa_rounding' => 'half_up',    // truncate | half_up
        'subject_mark_rounding' => 'none', // none | half_up | ceil (to a whole mark, before grading)
        'fail_if_any_subject_fails' => true,
        'optional_fail_counts' => false, // failing the optional subject fails the result
        'gpa_zero_on_fail' => true,
        'absent_is_fail' => true,        // false: an absent subject is left out of the result
        'merit_order' => 'gpa_total',    // gpa_total | total
        'rank_failed' => false,          // give failed students a position after all passed
    ];

    public const OPTIONS = [
        'gpa_method' => ['average', 'optional_bonus', 'none'],
        'gpa_rounding' => ['truncate', 'half_up'],
        'subject_mark_rounding' => ['none', 'half_up', 'ceil'],
        'merit_order' => ['gpa_total', 'total'],
    ];

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    public static function normalize(array $rules): array
    {
        $out = [];

        foreach (self::DEFAULTS as $key => $default) {
            $value = array_key_exists($key, $rules) ? $rules[$key] : $default;

            $out[$key] = match (true) {
                is_bool($default) => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                is_int($default) => max(0, min(2, (int) $value)),
                $key === 'gpa_cap' => $value === null || $value === '' ? null : Hundredths::format(Hundredths::parse((string) $value)),
                $key === 'optional_bonus_above' => Hundredths::format(Hundredths::parse((string) $value)),
                isset(self::OPTIONS[$key]) => in_array($value, self::OPTIONS[$key], true) ? $value : $default,
                default => $value,
            };
        }

        return $out;
    }

    /**
     * Laravel validation rules for the rules form.
     *
     * @return array<string, mixed>
     */
    public static function validation(): array
    {
        return [
            'rules.gpa_method' => ['required', 'in:'.implode(',', self::OPTIONS['gpa_method'])],
            'rules.optional_bonus_above' => ['required', 'numeric', 'min:0', 'max:99.99', 'decimal:0,2'],
            'rules.gpa_cap' => ['nullable', 'numeric', 'min:0', 'max:99.99', 'decimal:0,2'],
            'rules.gpa_decimals' => ['required', 'integer', 'between:0,2'],
            'rules.gpa_rounding' => ['required', 'in:'.implode(',', self::OPTIONS['gpa_rounding'])],
            'rules.subject_mark_rounding' => ['required', 'in:'.implode(',', self::OPTIONS['subject_mark_rounding'])],
            'rules.fail_if_any_subject_fails' => ['boolean'],
            'rules.optional_fail_counts' => ['boolean'],
            'rules.gpa_zero_on_fail' => ['boolean'],
            'rules.absent_is_fail' => ['boolean'],
            'rules.merit_order' => ['required', 'in:'.implode(',', self::OPTIONS['merit_order'])],
            'rules.rank_failed' => ['boolean'],
        ];
    }
}
