<?php

namespace App\Modules\Finance\Models;

use App\Models\User;
use App\Modules\People\Models\Student;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * Money received (or, for online payments, expected). Once successful the row
 * never changes; a mistake or refund is a FeePaymentReversal.
 *
 * @property int $id
 * @property string|null $receipt_no
 * @property int $student_id
 * @property string $method
 * @property string|null $reference
 * @property string|null $gateway
 * @property string|null $gateway_transaction_id
 * @property string $public_id
 * @property string $amount
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $received_at
 * @property array<string, mixed>|null $gateway_payload
 * @property list<array{invoice_id: int, amount: string}>|null $intended_allocations
 * @property int $reconcile_attempts
 */
class FeePayment extends Model
{
    use BelongsToSchool;

    public const METHODS = ['cash', 'bank', 'cheque', 'mobile_banking', 'online'];

    protected $attributes = ['status' => 'pending', 'currency' => 'BDT'];

    protected $fillable = ['student_id', 'method', 'reference', 'gateway', 'amount', 'idempotency_key', 'notes', 'intended_allocations'];

    protected function casts(): array
    {
        return ['received_at' => 'datetime', 'gateway_payload' => 'array', 'intended_allocations' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(function (FeePayment $p) {
            $p->public_id ??= strtolower((string) Str::ulid());
        });

        static::updating(function (FeePayment $p) {
            if ($p->getOriginal('status') === 'successful') {
                throw new \LogicException('A successful payment is immutable; record a reversal instead.');
            }
        });

        static::deleting(fn () => throw new \LogicException('Payments are never deleted.'));
    }

    public function isLive(): bool
    {
        return $this->status === 'successful' && ! $this->reversal()->exists();
    }

    /** @return HasMany<FeePaymentAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(FeePaymentAllocation::class);
    }

    /** @return HasOne<FeePaymentReversal, $this> */
    public function reversal(): HasOne
    {
        return $this->hasOne(FeePaymentReversal::class);
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<User, $this> */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
