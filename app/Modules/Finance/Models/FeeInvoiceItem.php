<?php

namespace App\Modules\Finance\Models;

use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only invoice line. Corrections are new lines (waiver/adjustment).
 *
 * @property int $id
 * @property int $fee_invoice_id
 * @property string $kind
 * @property int|null $fee_category_id
 * @property string $description
 * @property string $amount
 * @property string $discount_amount
 * @property string|null $discount_note
 * @property string $line_total
 */
class FeeInvoiceItem extends Model
{
    use BelongsToSchool;

    public const UPDATED_AT = null;

    protected $fillable = ['fee_invoice_id', 'kind', 'fee_category_id', 'fee_structure_id', 'description', 'amount', 'discount_amount', 'discount_note', 'line_total', 'created_by'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Invoice items are immutable; add a waiver or adjustment line instead.'));
        static::deleting(fn () => throw new \LogicException('Invoice items are immutable; add a waiver or adjustment line instead.'));
    }

    /** @return BelongsTo<FeeCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(FeeCategory::class, 'fee_category_id');
    }
}
