<?php

namespace App\Modules\Finance\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cancels the effect of a whole payment. "reversal": recorded by mistake or
 * bounced; "refund": money returned to the family.
 *
 * @property int $id
 * @property int $fee_payment_id
 * @property string $type
 * @property string $amount
 * @property string $reason
 */
class FeePaymentReversal extends Model
{
    use BelongsToSchool;

    public const UPDATED_AT = null;

    protected $fillable = ['fee_payment_id', 'type', 'amount', 'reason', 'refund_method', 'refund_reference', 'created_by'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Reversals are immutable.'));
        static::deleting(fn () => throw new \LogicException('Reversals are immutable.'));
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
