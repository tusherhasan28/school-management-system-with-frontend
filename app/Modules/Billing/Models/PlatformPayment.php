<?php

namespace App\Modules\Billing\Models;

use App\Models\PlatformUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A payment from a school to the platform (central). Append-only.
 *
 * @property int $id
 * @property int $platform_invoice_id
 * @property string $method
 * @property string|null $transaction_id
 * @property string $amount
 * @property Carbon $received_at
 */
class PlatformPayment extends Model
{
    protected $fillable = ['school_id', 'platform_invoice_id', 'method', 'gateway', 'transaction_id', 'amount', 'currency', 'status', 'received_at', 'recorded_by', 'notes'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'received_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }

    /** @return BelongsTo<PlatformInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PlatformInvoice::class, 'platform_invoice_id');
    }

    /** @return BelongsTo<PlatformUser, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'recorded_by');
    }
}
