<?php

namespace Tests\Concerns;

use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\PlanFeature;

/** Central plans for billing tests. */
trait CreatesPlans
{
    /** @param  array<string, int|bool|null>  $features */
    protected function plan(string $code = 'basic', array $features = [], array $attributes = []): Plan
    {
        $plan = Plan::create($attributes + [
            'code' => $code, 'name' => ucfirst($code), 'price_monthly' => '2000.00', 'price_yearly' => '20000.00', 'trial_days' => 14, 'grace_days' => 7,
        ]);

        $defaults = ['students' => 500, 'staff' => 50, 'sms_per_month' => 1000, 'storage_mb' => 1024, 'website' => true, 'parent_portal' => true,
            'student_portal' => true, 'online_payments' => true, 'sms' => true, 'bulk_import' => true];

        foreach ($features + $defaults as $key => $value) {
            PlanFeature::create(['plan_id' => $plan->id, 'feature_key' => $key, 'feature_value' => $value]);
        }

        return $plan->fresh('features');
    }
}
