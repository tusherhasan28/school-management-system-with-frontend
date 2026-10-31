<?php

namespace Tests\Concerns;

use App\Modules\Finance\Models\FeeCategory;
use App\Modules\Finance\Models\FeeStructure;

/**
 * Fee categories and plans inside the CURRENT tenant, for a structure made by
 * CreatesAcademicStructure (session 2026, Class 6 A/B, Class 7 A).
 */
trait CreatesFees
{
    /** @return array{tuition: FeeCategory, transport: FeeCategory, exam: FeeCategory} */
    protected function feeCategories(): array
    {
        return [
            'tuition' => FeeCategory::create(['name' => 'Tuition']),
            'transport' => FeeCategory::create(['name' => 'Transport']),
            'exam' => FeeCategory::create(['name' => 'Exam']),
        ];
    }

    protected function feePlan(array $s, FeeCategory $category, string $amount, string $frequency = 'monthly', ?int $classId = null, string $start = '2026-01', string $appliesTo = 'class', ?string $name = null): FeeStructure
    {
        return FeeStructure::create([
            'academic_session_id' => $s['session']->id, 'class_id' => $classId, 'fee_category_id' => $category->id,
            'name' => $name ?? $category->name.' fee', 'amount' => $amount, 'frequency' => $frequency, 'start_period' => $start, 'applies_to' => $appliesTo,
        ]);
    }
}
