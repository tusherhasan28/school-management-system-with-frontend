<?php

namespace App\Modules\Finance\Models;

use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student's discount or scholarship for a session: a percentage or a fixed
 * amount off each matching invoice line (one category, or all).
 *
 * @property int $id
 * @property int $student_id
 * @property int $academic_session_id
 * @property int|null $fee_category_id
 * @property string $name
 * @property string $kind
 * @property string $value
 * @property bool $is_active
 */
class FeeDiscount extends Model
{
    use BelongsToSchool;

    protected $fillable = ['student_id', 'academic_session_id', 'fee_category_id', 'name', 'kind', 'value', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<FeeCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(FeeCategory::class, 'fee_category_id');
    }
}
