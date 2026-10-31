<?php

namespace App\Modules\Finance\Services;

use App\Models\User;
use App\Modules\Academics\Models\AcademicSession;
use App\Modules\Finance\Models\FeeDiscount;
use App\Modules\Finance\Models\FeeInvoice;
use App\Modules\Finance\Models\FeeInvoiceItem;
use App\Modules\Finance\Models\FeeStructure;
use App\Modules\Finance\Models\StudentFeeAssignment;
use App\Modules\People\Models\Student;
use App\Modules\People\Models\StudentEnrollment;
use App\Services\Audit\AuditLogger;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantSequence;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Invoices: generation per billing month, one-off invoices, late fees,
 * waivers/adjustments and cancellation. Items are append-only.
 */
class BillingService
{
    public function __construct(
        private readonly InvoiceLedger $ledger,
        private readonly TenantSequence $sequence,
        private readonly FinanceSettings $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Invoice every enrolled student (optionally one class) for a YYYY-MM period.
     * Safe to run again: students who already have a live invoice for the period
     * are skipped (database unique key on student + generation key).
     *
     * @return array{created: int, existing: int, empty: int}
     */
    public function generate(string $period, ?int $classId, CarbonImmutable $dueDate, ?User $user): array
    {
        $session = $this->sessionFor($period) ?? throw ValidationException::withMessages(['period' => __('finance.no_session_for_period')]);
        $structures = FeeStructure::query()->with('category')->where('academic_session_id', $session->id)->where('is_active', true)->get()
            ->filter(fn (FeeStructure $s) => $s->billsIn($period));

        $enrollments = StudentEnrollment::query()
            ->with('student:id,school_id,status')
            ->where('academic_session_id', $session->id)
            ->where('status', 'enrolled')
            ->when($classId, fn ($q) => $q->where('class_id', $classId))
            ->get()
            ->filter(fn ($e) => $e->student?->status === 'active');

        $assignments = StudentFeeAssignment::query()->where('is_active', true)->whereIn('student_id', $enrollments->pluck('student_id'))->get()->groupBy('student_id');
        $discounts = FeeDiscount::query()->where('academic_session_id', $session->id)->where('is_active', true)->whereIn('student_id', $enrollments->pluck('student_id'))->get()->groupBy('student_id');
        $existing = FeeInvoice::query()->where('generation_key', $this->key($period))->whereIn('student_id', $enrollments->pluck('student_id'))->pluck('student_id')->flip();

        $counts = ['created' => 0, 'existing' => 0, 'empty' => 0];

        foreach ($enrollments as $enrollment) {
            if ($existing->has($enrollment->student_id)) {
                $counts['existing']++;

                continue;
            }

            $lines = $this->linesFor($enrollment, $structures, $assignments->get($enrollment->student_id, collect()), $discounts->get($enrollment->student_id, collect()), $period);

            if ($lines === []) {
                $counts['empty']++;

                continue;
            }

            try {
                $this->createInvoice($enrollment->student_id, $session->id, $enrollment->class_id, $enrollment->section_id, $period, $dueDate, $lines, $user);
                $counts['created']++;
            } catch (UniqueConstraintViolationException) {
                $counts['existing']++; // generated concurrently by someone else
            }
        }

        $this->audit->tenant('finance.invoices_generated', null, [], ['period' => $period, 'class_id' => $classId] + $counts);

        return $counts;
    }

    /**
     * A one-off invoice (e.g. admission or ID card fee).
     *
     * @param  list<array{fee_category_id: int|null, description: string, amount: string}>  $items
     */
    public function createManual(Student $student, array $items, CarbonImmutable $dueDate, ?string $notes, User $user): FeeInvoice
    {
        $enrollment = StudentEnrollment::query()->where('student_id', $student->id)->where('status', 'enrolled')->latest('id')->first()
            ?? throw ValidationException::withMessages(['student' => __('finance.student_not_enrolled')]);

        $lines = array_map(fn ($i) => [
            'kind' => 'fee', 'fee_category_id' => $i['fee_category_id'] ?? null, 'fee_structure_id' => null,
            'description' => $i['description'], 'amount' => Money::parse((string) $i['amount']), 'discount' => 0, 'discount_note' => null,
        ], $items);

        if (array_sum(array_column($lines, 'amount')) <= 0) {
            throw ValidationException::withMessages(['items' => __('finance.amount_positive')]);
        }

        return $this->createInvoice($student->id, $enrollment->academic_session_id, $enrollment->class_id, $enrollment->section_id, null, $dueDate, $lines, $user, $notes);
    }

    /**
     * Append a waiver (reduces what is owed) or an adjustment (extra charge).
     */
    public function addLine(FeeInvoice $invoice, string $kind, string $description, string $amount, User $user): void
    {
        $value = Money::parse($amount);

        if ($value <= 0) {
            throw ValidationException::withMessages(['amount' => __('finance.amount_positive')]);
        }

        DB::transaction(function () use ($invoice, $kind, $description, $value, $user) {
            $invoice = FeeInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($invoice->status === 'cancelled') {
                throw ValidationException::withMessages(['invoice' => __('finance.invoice_cancelled')]);
            }

            if ($kind === 'waiver' && $value > $this->ledger->balance($invoice)) {
                throw ValidationException::withMessages(['amount' => __('finance.waiver_above_balance')]);
            }

            $signed = $kind === 'waiver' ? -$value : $value;
            FeeInvoiceItem::create([
                'fee_invoice_id' => $invoice->id, 'kind' => $kind, 'description' => $description,
                'amount' => Money::format($signed), 'discount_amount' => '0.00', 'line_total' => Money::format($signed), 'created_by' => $user->id,
            ]);
            $this->ledger->refresh($invoice);
            $this->audit->tenant('finance.invoice_'.$kind, $invoice, [], ['amount' => Money::format($value), 'description' => $description]);
        });
    }

    /**
     * Add the school's late fee once to an overdue invoice. Returns whether a fee was added.
     */
    public function applyLateFee(FeeInvoice $invoice, CarbonImmutable $today): bool
    {
        $s = $this->settings->all();

        if (! $s['late_fee_enabled'] || Money::parse((string) $s['late_fee_value']) <= 0) {
            return false;
        }

        return DB::transaction(function () use ($invoice, $today, $s) {
            $invoice = FeeInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $balance = $this->ledger->balance($invoice);
            $lateFrom = CarbonImmutable::parse($invoice->due_date->toDateString())->addDays((int) $s['late_fee_grace_days']);

            if ($balance <= 0 || $today->lessThanOrEqualTo($lateFrom) || $invoice->items()->where('kind', 'late_fee')->exists()) {
                return false;
            }

            $fee = $s['late_fee_kind'] === 'percent'
                ? Money::percentOf($balance, (string) $s['late_fee_value'])
                : Money::parse((string) $s['late_fee_value']);

            if ($fee <= 0) {
                return false;
            }

            FeeInvoiceItem::create([
                'fee_invoice_id' => $invoice->id, 'kind' => 'late_fee', 'description' => 'Late fee · বিলম্ব ফি', // stored text, read by both languages
                'amount' => Money::format($fee), 'discount_amount' => '0.00', 'line_total' => Money::format($fee),
            ]);
            $this->ledger->refresh($invoice);

            return true;
        });
    }

    /** Late fees for every overdue open invoice of the current school. */
    public function applyLateFees(CarbonImmutable $today): int
    {
        $count = 0;

        FeeInvoice::query()->open()->where('due_date', '<', $today->toDateString())->orderBy('id')->chunkById(200, function ($invoices) use ($today, &$count) {
            foreach ($invoices as $invoice) {
                $count += (int) $this->applyLateFee($invoice, $today);
            }
        });

        return $count;
    }

    public function cancel(FeeInvoice $invoice, string $reason, User $user): void
    {
        DB::transaction(function () use ($invoice, $reason, $user) {
            $invoice = FeeInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($invoice->status === 'cancelled') {
                return;
            }

            if ($this->ledger->paid($invoice) > 0) {
                throw ValidationException::withMessages(['invoice' => __('finance.cancel_has_payments')]);
            }

            $invoice->forceFill([
                'status' => 'cancelled', 'generation_key' => null, 'cancelled_at' => now(), 'cancelled_by' => $user->id, 'cancel_reason' => $reason,
            ])->save();
            $this->audit->tenant('finance.invoice_cancelled', $invoice, [], ['reason' => $reason]);
        });
    }

    public function sessionFor(string $period): ?AcademicSession
    {
        $start = CarbonImmutable::parse($period.'-01');

        return AcademicSession::query()
            ->where('start_date', '<=', $start->endOfMonth()->toDateString())
            ->where('end_date', '>=', $start->toDateString())
            ->orderByDesc('is_current')
            ->first();
    }

    /**
     * @param  Collection<int, FeeStructure>  $structures
     * @param  Collection<int, StudentFeeAssignment>  $assignments
     * @param  Collection<int, FeeDiscount>  $discounts
     * @return list<array<string, mixed>>
     */
    private function linesFor(StudentEnrollment $enrollment, Collection $structures, Collection $assignments, Collection $discounts, string $period): array
    {
        $byStructure = $assignments->keyBy('fee_structure_id');
        $lines = [];

        foreach ($structures as $structure) {
            if ($structure->class_id !== null && $structure->class_id !== $enrollment->class_id) {
                continue;
            }

            $assignment = $byStructure->get($structure->id);

            if ($structure->applies_to === 'assigned' && $assignment === null) {
                continue;
            }

            $amount = Money::parse($assignment?->custom_amount ?? $structure->amount);

            if ($amount <= 0) {
                continue; // waived for this student
            }

            $discount = 0;
            $notes = [];

            foreach ($discounts as $d) {
                if ($d->fee_category_id !== null && $d->fee_category_id !== $structure->fee_category_id) {
                    continue;
                }

                $off = $d->kind === 'percent' ? Money::percentOf($amount, $d->value) : Money::parse($d->value);
                $discount += $off;
                $notes[] = $d->name;
            }

            $discount = min($discount, $amount);

            $lines[] = [
                'kind' => 'fee', 'fee_category_id' => $structure->fee_category_id, 'fee_structure_id' => $structure->id,
                'description' => $structure->name.($structure->frequency !== 'one_time' && $structure->frequency !== 'yearly' ? ' — '.CarbonImmutable::parse($period.'-01')->format('M Y') : ''),
                'amount' => $amount, 'discount' => $discount, 'discount_note' => $notes ? implode(', ', $notes) : null,
            ];
        }

        return $lines;
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function createInvoice(int $studentId, int $sessionId, ?int $classId, ?int $sectionId, ?string $period, CarbonImmutable $dueDate, array $lines, ?User $user, ?string $notes = null): FeeInvoice
    {
        return DB::transaction(function () use ($studentId, $sessionId, $classId, $sectionId, $period, $dueDate, $lines, $user, $notes) {
            $invoice = FeeInvoice::create([
                'invoice_no' => $this->sequence->format('invoice', 'INV-{year}-{seq:5}'),
                'student_id' => $studentId, 'academic_session_id' => $sessionId, 'class_id' => $classId, 'section_id' => $sectionId,
                'billing_period' => $period, 'generation_key' => $period ? $this->key($period) : null,
                'issue_date' => now(app(\App\Support\Tenancy\TenantContext::class)->require()->timezone)->toDateString(),
                'due_date' => $dueDate->toDateString(), 'notes' => $notes, 'created_by' => $user?->id,
            ]);

            foreach ($lines as $l) {
                FeeInvoiceItem::create([
                    'fee_invoice_id' => $invoice->id, 'kind' => $l['kind'], 'fee_category_id' => $l['fee_category_id'], 'fee_structure_id' => $l['fee_structure_id'],
                    'description' => mb_substr($l['description'], 0, 190), 'amount' => Money::format($l['amount']), 'discount_amount' => Money::format($l['discount']),
                    'discount_note' => $l['discount_note'], 'line_total' => Money::format($l['amount'] - $l['discount']), 'created_by' => $user?->id,
                ]);
            }

            return $this->ledger->refresh($invoice);
        });
    }

    private function key(string $period): string
    {
        return 'period:'.$period;
    }
}
