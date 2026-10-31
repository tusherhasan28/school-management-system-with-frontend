<?php

namespace App\Modules\Billing\Models;

use App\Models\School;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Invoice from the platform to a school (central). Never deleted: unpaid
 * invoices are voided with a reason.
 *
 * @property int $id
 * @property int $school_id
 * @property int|null $subscription_id
 * @property string $invoice_number
 * @property string $total
 * @property string $status
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property Carbon $issued_at
 * @property Carbon $due_at
 * @property Carbon|null $paid_at
 */
class PlatformInvoice extends Model
{
    protected $fillable = ['school_id', 'subscription_id', 'invoice_number', 'description', 'period_start', 'period_end', 'subtotal', 'discount', 'tax', 'total', 'currency', 'issued_at', 'due_at', 'period_key'];

    protected $attributes = ['status' => 'unpaid', 'currency' => 'BDT', 'discount' => '0.00', 'tax' => '0.00'];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2', 'discount' => 'decimal:2', 'tax' => 'decimal:2', 'total' => 'decimal:2',
            'period_start' => 'datetime', 'period_end' => 'datetime', 'issued_at' => 'datetime', 'due_at' => 'datetime',
            'paid_at' => 'datetime', 'voided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => false);
    }

    public function isOverdue(): bool
    {
        return $this->status === 'unpaid' && $this->due_at->isPast();
    }

    /** @param  Builder<PlatformInvoice>  $query */
    public function scopeOverdue(Builder $query): void
    {
        $query->where('status', 'unpaid')->where('due_at', '<', now());
    }

    /** @return BelongsTo<School, $this> */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return HasMany<PlatformPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(PlatformPayment::class);
    }
}
