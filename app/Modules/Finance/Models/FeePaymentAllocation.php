<?php

namespace App\Modules\Finance\Models;

use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $fee_payment_id
 * @property int $fee_invoice_id
 * @property string $amount
 */
class FeePaymentAllocation extends Model
{
    use BelongsToSchool;

    public const UPDATED_AT = null;

    protected $fillable = ['fee_payment_id', 'fee_invoice_id', 'amount'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Allocations are immutable.'));
        static::deleting(fn () => throw new \LogicException('Allocations are immutable.'));
    }

    /** @return BelongsTo<FeePayment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(FeePayment::class, 'fee_payment_id');
    }

    /** @return BelongsTo<FeeInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(FeeInvoice::class, 'fee_invoice_id');
    }
}
