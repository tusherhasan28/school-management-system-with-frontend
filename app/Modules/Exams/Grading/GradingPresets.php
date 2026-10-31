<?php

namespace App\Modules\Exams\Grading;

/**
 * Starting points a school can copy and then edit. They are not authoritative:
 * each school must check bands, rounding and optional-subject rules against
 * its own board or institution instructions before publishing results.
 */
final class GradingPresets
{
    /**
     * @return array<string, array{name: string, description: string, rules: array<string, mixed>, items: list<array<string, mixed>>}>
     */
    public static function all(): array
    {
        return [
            'gpa5' => [
                'name' => 'GPA 5 (secondary)',
                'description' => 'Modelled on the common 5-point scale used for secondary classes. Check rounding and 4th-subject rules with your board.',
                'rules' => GradingRules::DEFAULTS,
                'items' => [
                    ['grade' => 'A+', 'min_percentage' => '80.00', 'max_percentage' => '100.00', 'grade_point' => '5.00', 'is_fail' => false, 'remarks' => 'Excellent', 'remarks_bn' => 'চমৎকার'],
                    ['grade' => 'A', 'min_percentage' => '70.00', 'max_percentage' => '79.99', 'grade_point' => '4.00', 'is_fail' => false, 'remarks' => 'Very good', 'remarks_bn' => 'অতি উত্তম'],
                    ['grade' => 'A-', 'min_percentage' => '60.00', 'max_percentage' => '69.99', 'grade_point' => '3.50', 'is_fail' => false, 'remarks' => 'Good', 'remarks_bn' => 'উত্তম'],
                    ['grade' => 'B', 'min_percentage' => '50.00', 'max_percentage' => '59.99', 'grade_point' => '3.00', 'is_fail' => false, 'remarks' => 'Satisfactory', 'remarks_bn' => 'সন্তোষজনক'],
                    ['grade' => 'C', 'min_percentage' => '40.00', 'max_percentage' => '49.99', 'grade_point' => '2.00', 'is_fail' => false, 'remarks' => 'Average', 'remarks_bn' => 'মাঝারি'],
                    ['grade' => 'D', 'min_percentage' => '33.00', 'max_percentage' => '39.99', 'grade_point' => '1.00', 'is_fail' => false, 'remarks' => 'Pass', 'remarks_bn' => 'পাস'],
                    ['grade' => 'F', 'min_percentage' => '0.00', 'max_percentage' => '32.99', 'grade_point' => '0.00', 'is_fail' => true, 'remarks' => 'Fail', 'remarks_bn' => 'অকৃতকার্য'],
                ],
            ],
            'percentage' => [
                'name' => 'Percentage grades (no GPA)',
                'description' => 'Letter grades from percentage only, e.g. for pre-primary and junior classes.',
                'rules' => ['gpa_method' => 'none', 'merit_order' => 'total'] + GradingRules::DEFAULTS,
                'items' => [
                    ['grade' => 'A+', 'min_percentage' => '80.00', 'max_percentage' => '100.00', 'grade_point' => '5.00', 'is_fail' => false, 'remarks' => 'Excellent', 'remarks_bn' => 'চমৎকার'],
                    ['grade' => 'A', 'min_percentage' => '70.00', 'max_percentage' => '79.99', 'grade_point' => '4.00', 'is_fail' => false, 'remarks' => 'Very good', 'remarks_bn' => 'অতি উত্তম'],
                    ['grade' => 'B', 'min_percentage' => '60.00', 'max_percentage' => '69.99', 'grade_point' => '3.00', 'is_fail' => false, 'remarks' => 'Good', 'remarks_bn' => 'উত্তম'],
                    ['grade' => 'C', 'min_percentage' => '50.00', 'max_percentage' => '59.99', 'grade_point' => '2.00', 'is_fail' => false, 'remarks' => 'Fair', 'remarks_bn' => 'মোটামুটি'],
                    ['grade' => 'D', 'min_percentage' => '40.00', 'max_percentage' => '49.99', 'grade_point' => '1.00', 'is_fail' => false, 'remarks' => 'Needs improvement', 'remarks_bn' => 'উন্নতি প্রয়োজন'],
                    ['grade' => 'F', 'min_percentage' => '0.00', 'max_percentage' => '39.99', 'grade_point' => '0.00', 'is_fail' => true, 'remarks' => 'Fail', 'remarks_bn' => 'অকৃতকার্য'],
                ],
            ],
        ];
    }
}
