<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One configurable feature or limit of a plan (central).
 *
 * @property int $plan_id
 * @property string $feature_key
 * @property mixed $feature_value
 */
class PlanFeature extends Model
{
    protected $fillable = ['plan_id', 'feature_key', 'feature_value'];

    protected function casts(): array
    {
        return ['feature_value' => 'json'];
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
