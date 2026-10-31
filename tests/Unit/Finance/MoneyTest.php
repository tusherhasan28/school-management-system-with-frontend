<?php

namespace Tests\Unit\Finance;

use App\Modules\Finance\Models\FeeStructure;
use App\Support\Money\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_parse_and_format(): void
    {
        $this->assertSame(150050, Money::parse('1,500.5'));
        $this->assertSame(-1205, Money::parse('-12.05'));
        $this->assertSame(1500, Money::parse(15));
        $this->assertSame('1500.50', Money::format(150050));
        $this->assertSame('-0.05', Money::format(-5));
        $this->assertSame(30001, Money::sum(['100.00', '200.01', null]));
    }

    public function test_percent_rounds_half_up_to_the_paisa(): void
    {
        $this->assertSame(15000, Money::percentOf(150000, '10'));     // 10% of 1500.00 = 150.00
        $this->assertSame(3, Money::percentOf(100, '2.5'));           // 2.5% of 1.00 = 0.025 → 0.03
        $this->assertSame(1250, Money::percentOf(10001, '12.5'));     // 12.5% of 100.01 = 12.50125 → 12.50
    }

    public function test_rejects_more_than_two_decimals(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::parse('1.005');
    }

    public function test_billing_months_by_frequency(): void
    {
        $plan = fn (string $f, string $start = '2026-01') => new FeeStructure(['frequency' => $f, 'start_period' => $start]);
        $months = fn (FeeStructure $s) => array_values(array_filter(array_map(fn ($m) => sprintf('2026-%02d', $m), range(1, 12)), fn ($p) => $s->billsIn($p)));

        $this->assertCount(12, $months($plan('monthly')));
        $this->assertSame(['2026-01', '2026-04', '2026-07', '2026-10'], $months($plan('quarterly')));
        $this->assertSame(['2026-03', '2026-09'], $months($plan('half_yearly', '2026-03')));
        $this->assertSame(['2026-06'], $months($plan('one_time', '2026-06')));
        $this->assertSame(['2026-01'], $months($plan('yearly')));
        $this->assertSame([], array_values(array_filter(['2025-12'], fn ($p) => $plan('monthly')->billsIn($p))));
    }
}
