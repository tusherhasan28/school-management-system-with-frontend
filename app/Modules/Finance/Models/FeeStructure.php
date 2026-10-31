<?php

namespace App\Modules\Finance\Models;

use App\Modules\Academics\Models\SchoolClass;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A fee charged in a session: to every student of a class (or all classes),
 * or only to students it is assigned to. Billed in start_period and then every
 * 1/3/6 months per frequency, within the session.
 *
 * @property int $id
 * @property int $academic_session_id
 * @property int|null $class_id
 * @property int $fee_category_id
 * @property string $name
 * @property string $amount
 * @property string $frequency
 * @property string $start_period
 * @property string $applies_to
 * @property bool $is_active
 */
class FeeStructure extends Model
{
    use BelongsToSchool;

    public const FREQUENCIES = ['one_time' => 0, 'monthly' => 1, 'quarterly' => 3, 'half_yearly' => 6, 'yearly' => 0];

    protected $fillable = ['academic_session_id', 'class_id', 'fee_category_id', 'name', 'amount', 'frequency', 'start_period', 'applies_to', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** Whether this fee is billed in the given YYYY-MM period (session bounds are checked by the caller). */
    public function billsIn(string $period): bool
    {
        if ($period < $this->start_period) {
            return false;
        }

        $step = self::FREQUENCIES[$this->frequency] ?? 0;

        if ($step === 0) {
            return $period === $this->start_period;
        }

        [$sy, $sm] = array_map('intval', explode('-', $this->start_period));
        [$py, $pm] = array_map('intval', explode('-', $period));

        return (($py * 12 + $pm) - ($sy * 12 + $sm)) % $step === 0;
    }

    /** @return BelongsTo<FeeCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(FeeCategory::class, 'fee_category_id');
    }

    /** @return BelongsTo<SchoolClass, $this> */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }
}
