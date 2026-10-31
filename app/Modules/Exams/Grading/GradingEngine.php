<?php

namespace App\Modules\Exams\Grading;

/**
 * Turns marks into subject grades, GPA, overall grade and merit positions.
 *
 * Pure and deterministic: no database, no clock, integer arithmetic only.
 * Rules come from GradingConfig; nothing about a particular board or school
 * is hardcoded. Bump VERSION whenever the calculation itself changes, so
 * stored results show which engine produced them.
 *
 * Input, per student:
 *   ['student_id', 'class_id', 'section_id', 'subjects' => [[
 *       'exam_subject_id', 'subject_id', 'type' (compulsory|optional|non_gpa),
 *       'status' (present|absent|exempt), 'full_marks', 'pass_marks' (strings),
 *       'components' => [['id', 'name', 'full_marks', 'pass_marks' (?string), 'marks' (?string)]],
 *   ]]]
 *
 * Subject rules (in order):
 *  1. exempt → left out entirely. absent → 0 marks; failed if absent_is_fail,
 *     otherwise left out like exempt.
 *  2. total = sum of component marks, then subject_mark_rounding.
 *  3. failed if any component is below its own pass mark, or the total is
 *     below the subject pass mark, or the percentage falls in a fail band.
 *  4. percentage = total / full × 100, rounded down to 0.01.
 *  5. grade = band for the percentage; a failed subject gets the fail band.
 *
 * Result rules:
 *  - At most one optional subject counts: the one with the highest grade
 *    point (then higher marks, then lower exam subject id). Any others are
 *    reported as non_gpa.
 *  - Totals and percentage cover compulsory + counted optional subjects.
 *  - GPA per gpa_method, rounded per gpa_rounding/gpa_decimals, capped at gpa_cap.
 *  - Fail per fail_if_any_subject_fails (optional only if optional_fail_counts);
 *    otherwise fail when the overall grade is a fail band.
 *  - Merit positions within the class and within the section; equal keys
 *    share a position (1, 2, 2, 4).
 */
final class GradingEngine
{
    public const VERSION = '1.0.0';

    /**
     * @param  list<array<string, mixed>>  $students
     * @return list<array<string, mixed>>
     */
    public function grade(GradingConfig $config, array $students): array
    {
        $results = array_map(fn (array $s) => $this->gradeStudent($config, $s), $students);

        return $this->rank($config, $results);
    }

    /**
     * @param  array<string, mixed>  $student
     * @return array<string, mixed>
     */
    private function gradeStudent(GradingConfig $config, array $student): array
    {
        $rules = $config->rules;
        $subjects = [];

        foreach ($student['subjects'] as $input) {
            $outcome = $this->gradeSubject($config, $input);

            if ($outcome !== null) {
                $subjects[] = $outcome;
            }
        }

        // Pick the single counted optional subject.
        $optional = array_filter($subjects, fn ($s) => $s['type'] === 'optional');
        uasort($optional, fn ($a, $b) => [$b['gp'], $b['obtained'], $a['exam_subject_id']] <=> [$a['gp'], $a['obtained'], $b['exam_subject_id']]);
        $countedOptionalKey = array_key_first($optional);

        foreach ($subjects as $key => &$s) {
            $s['counted_as'] = match (true) {
                $s['type'] === 'compulsory' => 'compulsory',
                $s['type'] === 'optional' && $key === $countedOptionalKey => 'optional',
                default => 'non_gpa',
            };
        }
        unset($s);

        $compulsory = array_values(array_filter($subjects, fn ($s) => $s['counted_as'] === 'compulsory'));
        $counted = array_values(array_filter($subjects, fn ($s) => $s['counted_as'] !== 'non_gpa'));
        $opt = $countedOptionalKey !== null ? $subjects[$countedOptionalKey] : null;

        $full = array_sum(array_column($counted, 'full'));
        $obtained = array_sum(array_column($counted, 'obtained'));
        $percentage = Hundredths::percentage($obtained, $full);

        $failedSubjects = count(array_filter($compulsory, fn ($s) => ! $s['passed']))
            + ($opt !== null && $rules['optional_fail_counts'] && ! $opt['passed'] ? 1 : 0);

        $gpa = null;
        $gpaWithoutOptional = null;
        $decimals = $rules['gpa_decimals'];
        $mode = $rules['gpa_rounding'];
        $cap = $rules['gpa_cap'] !== null ? Hundredths::parse($rules['gpa_cap']) : null;
        $capped = fn (int $v) => $cap !== null ? min($v, $cap) : $v;
        $compulsoryPoints = array_sum(array_column($compulsory, 'gp'));

        if ($rules['gpa_method'] === 'average' && $counted !== []) {
            $gpa = $capped(Hundredths::divide(array_sum(array_column($counted, 'gp')), count($counted), $decimals, $mode));
        } elseif ($rules['gpa_method'] === 'optional_bonus' && $compulsory !== []) {
            $bonus = $opt !== null ? max(0, $opt['gp'] - Hundredths::parse($rules['optional_bonus_above'])) : 0;
            $gpa = $capped(Hundredths::divide($compulsoryPoints + $bonus, count($compulsory), $decimals, $mode));
        }

        if ($gpa !== null && $compulsory !== []) {
            $gpaWithoutOptional = $capped(Hundredths::divide($compulsoryPoints, count($compulsory), $decimals, $mode));
        }

        $failedByRule = $rules['fail_if_any_subject_fails'] && $failedSubjects > 0;

        if ($failedByRule) {
            $letter = $config->failBand();
        } elseif ($gpa !== null) {
            $letter = $config->bandForGpa($gpa);
        } else {
            $letter = $config->bandFor($percentage);
        }

        $status = match (true) {
            $counted === [] => 'incomplete',
            $failedByRule || $letter['is_fail'] => 'fail',
            default => 'pass',
        };

        if ($status === 'fail' && $rules['gpa_zero_on_fail'] && $gpa !== null) {
            $gpa = 0;
            $gpaWithoutOptional = 0;
        }

        return [
            'student_id' => $student['student_id'],
            'class_id' => $student['class_id'],
            'section_id' => $student['section_id'] ?? null,
            'full' => $full,
            'obtained' => $obtained,
            'percentage' => $percentage,
            'gpa' => $gpa,
            'gpa_without_optional' => $gpaWithoutOptional,
            'letter_grade' => $status === 'incomplete' ? null : $letter['grade'],
            'status' => $status,
            'failed_subjects' => $failedSubjects,
            'subjects' => $subjects,
            'class_position' => null,
            'section_position' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>|null
     */
    private function gradeSubject(GradingConfig $config, array $input): ?array
    {
        $rules = $config->rules;
        $status = $input['status'];

        if ($status === 'exempt' || ($status === 'absent' && ! $rules['absent_is_fail'])) {
            return null;
        }

        $full = Hundredths::parse((string) $input['full_marks']);
        $pass = Hundredths::parse((string) ($input['pass_marks'] ?? '0'));
        $components = [];
        $componentFailed = false;
        $total = 0;

        foreach ($input['components'] as $c) {
            $marks = $status === 'present' ? Hundredths::parse($c['marks'] ?? '0') : 0;
            $cPass = isset($c['pass_marks']) && $c['pass_marks'] !== null && $c['pass_marks'] !== '' ? Hundredths::parse((string) $c['pass_marks']) : null;
            $cPassed = $status === 'present' && ($cPass === null || $marks >= $cPass);
            $componentFailed = $componentFailed || ! $cPassed;
            $total += $marks;

            $components[] = [
                'id' => $c['id'] ?? null,
                'name' => $c['name'],
                'name_bn' => $c['name_bn'] ?? null,
                'full' => Hundredths::format(Hundredths::parse((string) $c['full_marks'])),
                'pass' => $cPass !== null ? Hundredths::format($cPass) : null,
                'marks' => $status === 'present' ? Hundredths::format($marks) : null,
                'passed' => $cPassed,
            ];
        }

        $total = Hundredths::roundMark($total, $rules['subject_mark_rounding']);
        $percentage = Hundredths::percentage($total, $full);
        $band = $config->bandFor($percentage);
        $passed = $status === 'present' && ! $componentFailed && $total >= $pass && ! $band['is_fail'];

        if (! $passed) {
            $band = $config->failBand();
        }

        return [
            'exam_subject_id' => $input['exam_subject_id'],
            'subject_id' => $input['subject_id'],
            'type' => $input['type'],
            'status' => $status,
            'full' => $full,
            'obtained' => $total,
            'percentage' => $percentage,
            'grade' => $band['grade'],
            'gp' => $band['gp'],
            'passed' => $passed,
            'components' => $components,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return list<array<string, mixed>>
     */
    private function rank(GradingConfig $config, array $results): array
    {
        $rules = $config->rules;
        $key = fn (array $r) => $rules['merit_order'] === 'total'
            ? [$r['status'] === 'pass' ? 1 : 0, $r['obtained']]
            : [$r['status'] === 'pass' ? 1 : 0, $r['gpa'] ?? 0, $r['obtained']];

        $eligible = fn (array $r) => $r['status'] === 'pass' || ($rules['rank_failed'] && $r['status'] === 'fail');

        foreach (['class_position' => fn ($r) => $r['class_id'], 'section_position' => fn ($r) => $r['class_id'].'-'.($r['section_id'] ?? '')] as $field => $group) {
            $groups = [];

            foreach ($results as $i => $r) {
                if ($eligible($r)) {
                    $groups[$group($r)][] = $i;
                }
            }

            foreach ($groups as $indexes) {
                usort($indexes, fn ($a, $b) => [$key($results[$b]), $results[$a]['student_id']] <=> [$key($results[$a]), $results[$b]['student_id']]);
                $position = 0;
                $previous = null;

                foreach ($indexes as $n => $i) {
                    $k = $key($results[$i]);

                    if ($k !== $previous) {
                        $position = $n + 1;
                        $previous = $k;
                    }

                    $results[$i][$field] = $position;
                }
            }
        }

        return $results;
    }
}
