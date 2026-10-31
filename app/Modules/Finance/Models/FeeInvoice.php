<?php

namespace App\Modules\Finance\Models;

use App\Modules\Academics\Models\SchoolClass;
use App\Modules\Academics\Models\Section;
use App\Modules\People\Models\Student;
use App\Support\Money\Money;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * total and paid_total are caches. The source of truth is the sum of items
 * and the allocations of successful, unreversed payments; InvoiceLedger
 * recomputes both inside every transaction that changes them.
 * "Overdue" is derived (due date passed, balance left), never stored.
 *
 * @property int $id
 * @property string $invoice_no
 * @property int $student_id
 * @property int $academic_session_id
 * @property int|null $class_id
 * @property int|null $section_id
 * @property string|null $billing_period
 * @property \Illuminate\Support\Carbon $issue_date
 * @property \Illuminate\Support\Carbon $due_date
 * @property string $total
 * @property string $paid_total
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 */
class FeeInvoice extends Model
{
    use BelongsToSchool;

    protected $attributes = ['status' => 'unpaid', 'total' => '0.00', 'paid_total' => '0.00'];

    protected $fillable = ['invoice_no', 'student_id', 'academic_session_id', 'class_id', 'section_id', 'billing_period', 'generation_key', 'issue_date', 'due_date', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['issue_date' => 'date', 'due_date' => 'date', 'cancelled_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Invoices are never deleted; cancel them instead.'));
    }

    public function balance(): int
    {
        return $this->status === 'cancelled' ? 0 : Money::parse($this->total) - Money::parse($this->paid_total);
    }

    public function isOverdue(?string $today = null): bool
    {
        return $this->status !== 'cancelled' && $this->balance() > 0 && $this->due_date->toDateString() < ($today ?? now()->toDateString());
    }

    /** Unpaid or partly paid, not cancelled. @param Builder<FeeInvoice> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', ['unpaid', 'partially_paid']);
    }

    /** @return HasMany<FeeInvoiceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(FeeInvoiceItem::class)->orderBy('id');
    }

    /** @return HasMany<FeePaymentAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(FeePaymentAllocation::class);
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<SchoolClass, $this> */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /** @return BelongsTo<Section, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }
}
