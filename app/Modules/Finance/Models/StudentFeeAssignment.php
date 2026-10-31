<?php

namespace App\Modules\Finance\Models;

use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Opts a student into an "assigned" fee, or overrides a class fee's amount
 * for one student (custom_amount 0 waives it).
 *
 * @property int $id
 * @property int $student_id
 * @property int $fee_structure_id
 * @property string|null $custom_amount
 * @property bool $is_active
 */
class StudentFeeAssignment extends Model
{
    use BelongsToSchool;

    protected $fillable = ['student_id', 'fee_structure_id', 'custom_amount', 'note', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<FeeStructure, $this> */
    public function structure(): BelongsTo
    {
        return $this->belongsTo(FeeStructure::class, 'fee_structure_id');
    }
}
