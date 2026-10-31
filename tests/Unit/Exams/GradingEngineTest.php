<?php

namespace Tests\Unit\Exams;

use App\Modules\Exams\Grading\GradingConfig;
use App\Modules\Exams\Grading\GradingEngine;
use App\Modules\Exams\Grading\GradingPresets;
use App\Modules\Exams\Grading\Hundredths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Hand-calculated fixtures for the grading engine.
 *
 * These are illustrative cases worked out from the rules in GradingEngine's
 * docblock, not school-approved samples. When a pilot school supplies its
 * approved marksheets, add them here as additional fixtures.
 */
class GradingEngineTest extends TestCase
{
    private function config(array $rules = [], string $preset = 'gpa5'): GradingConfig
    {
        $p = GradingPresets::all()[$preset];

        return new GradingConfig($p['items'], $rules + $p['rules'], $p['name']);
    }

    /** A one-paper subject out of $full. */
    private function subject(int $id, string|null $marks, string $type = 'compulsory', string $status = 'present', string $full = '100', string $pass = '33'): array
    {
        return [
            'exam_subject_id' => $id, 'subject_id' => $id, 'type' => $type, 'status' => $status,
            'full_marks' => $full, 'pass_marks' => $pass,
            'components' => [['id' => $id * 10, 'name' => 'Written', 'full_marks' => $full, 'pass_marks' => null, 'marks' => $marks]],
        ];
    }

    private function student(int $id, array $subjects, int $class = 1, ?int $section = 1): array
    {
        return ['student_id' => $id, 'class_id' => $class, 'section_id' => $section, 'subjects' => $subjects];
    }

    private function one(GradingConfig $config, array $subjects): array
    {
        return (new GradingEngine)->grade($config, [$this->student(1, $subjects)])[0];
    }

    private function fmt(?int $v): ?string
    {
        return $v === null ? null : Hundredths::format($v);
    }

    public function test_hundredths_parse_and_format(): void
    {
        $this->assertSame(1250, Hundredths::parse('12.5'));
        $this->assertSame(1205, Hundredths::parse('12.05'));
        $this->assertSame(1200, Hundredths::parse(12));
        $this->assertSame('0.05', Hundredths::format(5));
        $this->assertSame(6716, Hundredths::percentage(40300, 60000)); // 67.1666… → 67.16
        $this->expectException(\InvalidArgumentException::class);
        Hundredths::parse('1.005');
    }

    /** Fixture 1 — five compulsory subjects and a 4th subject. */
    public function test_optional_subject_adds_points_above_two(): void
    {
        $r = $this->one($this->config(), [
            $this->subject(1, '85'), $this->subject(2, '72'), $this->subject(3, '65'),
            $this->subject(4, '55'), $this->subject(5, '45'), $this->subject(6, '81', 'optional'),
        ]);

        // (5.00 + 4.00 + 3.50 + 3.00 + 2.00) + (5.00 − 2.00) = 20.50 ÷ 5 = 4.10
        $this->assertSame('4.10', $this->fmt($r['gpa']));
        $this->assertSame('3.50', $this->fmt($r['gpa_without_optional']));
        $this->assertSame('A', $r['letter_grade']);
        $this->assertSame('pass', $r['status']);
        $this->assertSame('600.00', $this->fmt($r['full']));
        $this->assertSame('403.00', $this->fmt($r['obtained']));
        $this->assertSame('67.16', $this->fmt($r['percentage']));
        $this->assertSame(['A+', 'A', 'A-', 'B', 'C', 'A+'], array_column($r['subjects'], 'grade'));
        $this->assertSame('optional', $r['subjects'][5]['counted_as']);
    }

    /** Fixture 2 — GPA is capped. */
    public function test_gpa_is_capped(): void
    {
        $subjects = array_map(fn ($i) => $this->subject($i, '90'), range(1, 5));
        $subjects[] = $this->subject(6, '95', 'optional');

        $r = $this->one($this->config(), $subjects); // (25 + 3) ÷ 5 = 5.60 → 5.00
        $this->assertSame('5.00', $this->fmt($r['gpa']));
        $this->assertSame('A+', $r['letter_grade']);

        $r = $this->one($this->config(['gpa_cap' => null]), $subjects);
        $this->assertSame('5.60', $this->fmt($r['gpa']));
    }

    /** Fixture 3 — rounding of 12.50 ÷ 3 = 4.1666… */
    public function test_gpa_rounding_modes(): void
    {
        $subjects = [$this->subject(1, '90'), $this->subject(2, '75'), $this->subject(3, '65')];

        $this->assertSame('4.17', $this->fmt($this->one($this->config(['gpa_rounding' => 'half_up']), $subjects)['gpa']));
        $this->assertSame('4.16', $this->fmt($this->one($this->config(['gpa_rounding' => 'truncate']), $subjects)['gpa']));
        $this->assertSame('4.20', $this->fmt($this->one($this->config(['gpa_decimals' => 1]), $subjects)['gpa']));
        $this->assertSame('4.00', $this->fmt($this->one($this->config(['gpa_decimals' => 0]), $subjects)['gpa']));
    }

    /** Fixture 4 — a component below its own pass mark fails the subject. */
    public function test_component_pass_marks(): void
    {
        $science = [
            'exam_subject_id' => 2, 'subject_id' => 2, 'type' => 'compulsory', 'status' => 'present', 'full_marks' => '100', 'pass_marks' => '33',
            'components' => [
                ['id' => 21, 'name' => 'Written', 'full_marks' => '70', 'pass_marks' => '23', 'marks' => '60'],
                ['id' => 22, 'name' => 'MCQ', 'full_marks' => '30', 'pass_marks' => '10', 'marks' => '8'],
            ],
        ];

        $r = $this->one($this->config(), [$this->subject(1, '80'), $science]);
        $s = $r['subjects'][1];

        $this->assertSame('68.00', $this->fmt($s['obtained']));
        $this->assertFalse($s['passed']);
        $this->assertSame('F', $s['grade']);
        $this->assertFalse($s['components'][1]['passed']);
        $this->assertSame(['fail', 'F', '0.00', 1], [$r['status'], $r['letter_grade'], $this->fmt($r['gpa']), $r['failed_subjects']]);
    }

    /** Fixture 5 — failing the optional subject does not fail the result, unless configured. */
    public function test_optional_subject_failure(): void
    {
        $subjects = [$this->subject(1, '75'), $this->subject(2, '72'), $this->subject(3, '70'), $this->subject(4, '71'), $this->subject(5, '20', 'optional')];

        $r = $this->one($this->config(), $subjects);
        $this->assertSame(['pass', '4.00', 'A', 0], [$r['status'], $this->fmt($r['gpa']), $r['letter_grade'], $r['failed_subjects']]);

        $r = $this->one($this->config(['optional_fail_counts' => true]), $subjects);
        $this->assertSame(['fail', '0.00', 'F', 1], [$r['status'], $this->fmt($r['gpa']), $r['letter_grade'], $r['failed_subjects']]);
    }

    /** Fixture 6 — absence. */
    public function test_absent_subject(): void
    {
        $subjects = [$this->subject(1, '80'), $this->subject(2, null, status: 'absent')];

        $r = $this->one($this->config(), $subjects);
        $this->assertSame(['fail', 'F', 1], [$r['status'], $r['subjects'][1]['grade'], $r['failed_subjects']]);
        $this->assertSame('200.00', $this->fmt($r['full']));

        $r = $this->one($this->config(['absent_is_fail' => false]), $subjects);
        $this->assertSame(['pass', '5.00', '100.00'], [$r['status'], $this->fmt($r['gpa']), $this->fmt($r['full'])]);
        $this->assertCount(1, $r['subjects']);
    }

    /** Fixture 7 — exempt subjects (e.g. the other religion paper) are left out. */
    public function test_exempt_subject_is_left_out(): void
    {
        $r = $this->one($this->config(), [$this->subject(1, '80'), $this->subject(2, '70'), $this->subject(3, null, status: 'exempt')]);

        $this->assertCount(2, $r['subjects']);
        $this->assertSame(['4.50', '200.00'], [$this->fmt($r['gpa']), $this->fmt($r['full'])]);
    }

    /** Fixture 8 — only the best optional subject counts. */
    public function test_only_best_optional_subject_counts(): void
    {
        $r = $this->one($this->config(), [
            $this->subject(1, '70'), $this->subject(2, '70'),
            $this->subject(3, '61', 'optional'), $this->subject(4, '85', 'optional'),
        ]);

        $this->assertSame(['compulsory', 'compulsory', 'non_gpa', 'optional'], array_column($r['subjects'], 'counted_as'));
        // (4.00 + 4.00) + (5.00 − 2.00) = 11.00 ÷ 2 = 5.50 → capped 5.00; totals exclude the non-GPA paper
        $this->assertSame(['5.00', '300.00', '225.00'], [$this->fmt($r['gpa']), $this->fmt($r['full']), $this->fmt($r['obtained'])]);
    }

    /** Fixture 9 — percentages are rounded down, never up into a higher band. */
    public function test_percentage_boundaries(): void
    {
        $r = $this->one($this->config(), [$this->subject(1, '59.99', full: '75', pass: '25')]);
        $this->assertSame(['79.98', 'A'], [$this->fmt($r['subjects'][0]['percentage']), $r['subjects'][0]['grade']]);

        $r = $this->one($this->config(), [$this->subject(1, '60', full: '75', pass: '25')]);
        $this->assertSame(['80.00', 'A+'], [$this->fmt($r['subjects'][0]['percentage']), $r['subjects'][0]['grade']]);

        $r = $this->one($this->config(), [$this->subject(1, '32.99')]);
        $this->assertSame(['F', false], [$r['subjects'][0]['grade'], $r['subjects'][0]['passed']]);
    }

    /** Fixture 10 — rounding a subject total to a whole mark before grading. */
    public function test_subject_mark_rounding(): void
    {
        $subjects = [$this->subject(1, '32.5')];

        $this->assertSame(['F', false], [$this->one($this->config(), $subjects)['subjects'][0]['grade'], $this->one($this->config(), $subjects)['subjects'][0]['passed']]);

        $r = $this->one($this->config(['subject_mark_rounding' => 'half_up']), $subjects);
        $this->assertSame(['33.00', 'D', true], [$this->fmt($r['subjects'][0]['obtained']), $r['subjects'][0]['grade'], $r['subjects'][0]['passed']]);
    }

    /** Fixture 11 — merit positions: ties share a position; failed students are not ranked. */
    public function test_merit_positions(): void
    {
        $s = fn (array $marks) => array_map(fn ($m, $i) => $this->subject($i + 1, $m), $marks, array_keys($marks));
        $students = [
            $this->student(10, $s(['90', '90', '90', '80']), section: 1), // 5.00, 350
            $this->student(11, $s(['90', '90', '90', '80']), section: 2), // 5.00, 350
            $this->student(12, $s(['95', '95', '95', '75']), section: 1), // 4.75, 360
            $this->student(13, $s(['95', '95', '95', '20']), section: 1), // fail
            $this->student(14, $s(['50', '50', '50', '50']), section: 2), // 3.00, 200
        ];

        $byId = fn (array $rs) => collect($rs)->keyBy('student_id')->map(fn ($r) => [$r['class_position'], $r['section_position']])->all();

        $this->assertSame(
            [10 => [1, 1], 11 => [1, 1], 12 => [3, 2], 13 => [null, null], 14 => [4, 2]],
            $byId((new GradingEngine)->grade($this->config(), $students)),
        );

        // By total marks: 360 ranks first.
        $this->assertSame(
            [10 => [2, 2], 11 => [2, 1], 12 => [1, 1], 13 => [null, null], 14 => [4, 2]],
            $byId((new GradingEngine)->grade($this->config(['merit_order' => 'total']), $students)),
        );

        // Ranking failed students puts them after everyone who passed.
        $this->assertSame(5, collect((new GradingEngine)->grade($this->config(['rank_failed' => true]), $students))->firstWhere('student_id', 13)['class_position']);
    }

    /** Fixture 12 — no GPA: the overall grade comes from the overall percentage. */
    public function test_percentage_only_scale(): void
    {
        $r = $this->one($this->config(preset: 'percentage'), [$this->subject(1, '72'), $this->subject(2, '64')]);

        $this->assertNull($r['gpa']);
        $this->assertSame(['68.00', 'B', 'pass'], [$this->fmt($r['percentage']), $r['letter_grade'], $r['status']]);
    }

    /** Fixture 13 — plain average counts the optional subject like any other. */
    public function test_average_method(): void
    {
        $r = $this->one($this->config(['gpa_method' => 'average']), [$this->subject(1, '85'), $this->subject(2, '65'), $this->subject(3, '45', 'optional')]);

        $this->assertSame('3.50', $this->fmt($r['gpa'])); // (5.00 + 3.50 + 2.00) ÷ 3
    }

    /** Without the any-subject rule, a failed subject only lowers the GPA. */
    public function test_fail_rule_can_be_turned_off(): void
    {
        $r = $this->one($this->config(['fail_if_any_subject_fails' => false]), [$this->subject(1, '90'), $this->subject(2, '90'), $this->subject(3, '10')]);

        // 3.33 reaches B (3.00)
        $this->assertSame(['pass', '3.33', 'B', 1], [$r['status'], $this->fmt($r['gpa']), $r['letter_grade'], $r['failed_subjects']]);
    }

    public function test_same_input_in_any_order_gives_same_result(): void
    {
        $students = [];

        foreach (range(1, 12) as $id) {
            $students[] = $this->student($id, [$this->subject(1, (string) (30 + $id * 5)), $this->subject(2, (string) (95 - $id * 3))], section: $id % 2 + 1);
        }

        $a = collect((new GradingEngine)->grade($this->config(), $students))->keyBy('student_id')->sortKeys()->all();
        $b = collect((new GradingEngine)->grade($this->config(), array_reverse($students)))->keyBy('student_id')->sortKeys()->all();

        $this->assertSame($a, $b);
    }

    public function test_snapshot_reproduces_the_same_result(): void
    {
        $config = $this->config(['gpa_rounding' => 'truncate']);
        $copy = GradingConfig::fromSnapshot(json_decode(json_encode($config->snapshot()), true));
        $subjects = [$this->subject(1, '90'), $this->subject(2, '75'), $this->subject(3, '65')];

        $this->assertSame($this->one($config, $subjects), $this->one($copy, $subjects));
        $this->assertSame($config->snapshot(), $copy->snapshot());
    }

    /** @return array<string, array{0: list<array<string, mixed>>, 1: list<string>}> */
    public static function bandCases(): array
    {
        $ok = GradingPresets::all()['gpa5']['items'];
        $gap = $ok;
        $gap[1]['max_percentage'] = '78.00';
        $noFail = array_map(fn ($b) => ['is_fail' => false] + $b, $ok);
        $short = $ok;
        $short[0]['max_percentage'] = '99.00';

        return [
            'preset is valid' => [$ok, []],
            'gap between bands' => [$gap, ['exams.band_coverage']],
            'no fail band' => [$noFail, ['exams.band_fail_missing']],
            'does not reach 100' => [$short, ['exams.band_coverage']],
            'one band' => [[$ok[0]], ['exams.bands_min_two']],
        ];
    }

    #[DataProvider('bandCases')]
    public function test_band_validation(array $bands, array $errors): void
    {
        $this->assertSame($errors, GradingConfig::bandErrors($bands));
    }
}
