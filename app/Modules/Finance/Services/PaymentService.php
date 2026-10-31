<?php

namespace App\Modules\Finance\Services;

use App\Models\User;
use App\Modules\Finance\Models\FeeInvoice;
use App\Modules\Finance\Models\FeePayment;
use App\Modules\Finance\Models\FeePaymentAllocation;
use App\Modules\Finance\Models\FeePaymentReversal;
use App\Modules\People\Models\Student;
use App\Services\Audit\AuditLogger;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantSequence;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recording money against invoices.
 *
 * - Every payment is allocated to invoices inside one transaction with the
 *   student's open invoices locked, so two cashiers cannot over-collect.
 * - A form submitted twice (same idempotency key) returns the first payment.
 * - No advance/credit balance: a payment may not exceed what is owed.
 * - Reversals and refunds are new rows; the payment itself never changes.
 */
class PaymentService
{
    public function __construct(
        private readonly InvoiceLedger $ledger,
        private readonly TenantSequence $sequence,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{amount: string, method: string, reference?: string|null, notes?: string|null, received_at?: CarbonInterface|null, idempotency_key?: string|null}  $data
     * @param  array<int, string>|null  $allocations  invoice id => amount; null = oldest due first
     */
    public function record(Student $student, array $data, ?array $allocations, User $user): FeePayment
    {
        $key = $data['idempotency_key'] ?? null;

        if ($key && ($existing = FeePayment::query()->where('idempotency_key', $key)->first())) {
            return $existing;
        }

        $amount = Money::parse($data['amount']);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => __('finance.amount_positive')]);
        }

        try {
            return $this->store($student, $data, $allocations, $user, $amount, $key);
        } catch (UniqueConstraintViolationException $e) {
            // The same form arrived twice at the same moment: return the first payment.
            return $key ? FeePayment::query()->where('idempotency_key', $key)->firstOrFail() : throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>|null  $allocations
     */
    private function store(Student $student, array $data, ?array $allocations, User $user, int $amount, ?string $key): FeePayment
    {
        return DB::transaction(function () use ($student, $data, $allocations, $user, $amount, $key) {
            $plan = $this->plan($student->id, $amount, $allocations);

            $payment = new FeePayment([
                'student_id' => $student->id, 'method' => $data['method'], 'reference' => $data['reference'] ?? null,
                'amount' => Money::format($amount), 'idempotency_key' => $key, 'notes' => $data['notes'] ?? null,
            ]);
            $payment->forceFill([
                'status' => 'successful',
                'receipt_no' => $this->receiptNo(),
                'received_at' => $data['received_at'] ?? now(),
                'received_by' => $user->id,
            ])->save();

            $this->allocate($payment, $plan);
            $this->audit->tenant('finance.payment_recorded', $payment, [], [
                'receipt_no' => $payment->receipt_no, 'amount' => $payment->amount, 'method' => $payment->method,
                'allocations' => collect($plan)->map(fn ($a, $id) => ['invoice_id' => $id, 'amount' => Money::format($a)])->values()->all(),
            ]);

            return $payment;
        });
    }

    /**
     * Cancel the effect of a whole payment: the money is treated as never
     * received (reversal) or as returned to the family (refund).
     *
     * @param  array{type: string, reason: string, refund_method?: string|null, refund_reference?: string|null}  $data
     */
    public function reverse(FeePayment $payment, array $data, User $user): FeePaymentReversal
    {
        return DB::transaction(function () use ($payment, $data, $user) {
            $payment = FeePayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->status !== 'successful') {
                throw ValidationException::withMessages(['payment' => __('finance.reverse_not_successful')]);
            }

            if ($payment->reversal()->exists()) {
                throw ValidationException::withMessages(['payment' => __('finance.already_reversed')]);
            }

            $invoices = FeeInvoice::query()->whereIn('id', $payment->allocations()->pluck('fee_invoice_id'))->lockForUpdate()->get();

            $reversal = FeePaymentReversal::create([
                'fee_payment_id' => $payment->id, 'type' => $data['type'], 'amount' => $payment->amount, 'reason' => $data['reason'],
                'refund_method' => $data['refund_method'] ?? null, 'refund_reference' => $data['refund_reference'] ?? null, 'created_by' => $user->id,
            ]);

            $invoices->each(fn (FeeInvoice $i) => $this->ledger->refresh($i));
            $this->audit->tenant('finance.payment_'.$data['type'], $payment, [], ['receipt_no' => $payment->receipt_no, 'amount' => $payment->amount, 'reason' => $data['reason']]);

            return $reversal;
        });
    }

    /**
     * Allocate a payment that is already marked successful (manual or online).
     * Caller holds the transaction.
     *
     * @param  array<int, int>  $plan  invoice id => paisa
     */
    public function allocate(FeePayment $payment, array $plan): void
    {
        foreach ($plan as $invoiceId => $paisa) {
            if ($paisa <= 0) {
                continue;
            }

            FeePaymentAllocation::create(['fee_payment_id' => $payment->id, 'fee_invoice_id' => $invoiceId, 'amount' => Money::format($paisa)]);
            $this->ledger->refresh(FeeInvoice::query()->findOrFail($invoiceId));
        }
    }

    /**
     * Work out invoice id => paisa for a payment, with the student's open
     * invoices locked. Caller holds the transaction.
     *
     * @param  array<int, string>|null  $requested
     * @return array<int, int>
     */
    public function plan(int $studentId, int $amount, ?array $requested, bool $allowPartialFit = false): array
    {
        /** @var Collection<int, FeeInvoice> $open */
        $open = FeeInvoice::query()
            ->where('student_id', $studentId)
            ->open()
            ->orderBy('due_date')->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $balances = $open->map(fn (FeeInvoice $i) => $this->ledger->balance($i))->filter(fn ($b) => $b > 0);
        $plan = [];

        if ($requested !== null) {
            foreach ($requested as $invoiceId => $value) {
                $paisa = Money::parse((string) $value);

                if ($paisa <= 0) {
                    continue;
                }

                if (! $balances->has((int) $invoiceId)) {
                    throw ValidationException::withMessages(['allocations' => __('finance.invoice_not_open')]);
                }

                if ($paisa > $balances[(int) $invoiceId]) {
                    throw ValidationException::withMessages(["allocations.{$invoiceId}" => __('finance.allocation_above_balance', ['balance' => Money::format($balances[(int) $invoiceId])])]);
                }

                $plan[(int) $invoiceId] = $paisa;
            }

            if (array_sum($plan) !== $amount) {
                throw ValidationException::withMessages(['allocations' => __('finance.allocations_must_match')]);
            }

            return $plan;
        }

        $remaining = $amount;

        foreach ($balances as $invoiceId => $balance) {
            if ($remaining <= 0) {
                break;
            }

            $plan[$invoiceId] = min($balance, $remaining);
            $remaining -= $plan[$invoiceId];
        }

        if ($remaining > 0 && ! $allowPartialFit) {
            throw ValidationException::withMessages(['amount' => __('finance.amount_above_due', ['due' => Money::format($balances->sum())])]);
        }

        return $plan;
    }

    public function receiptNo(): string
    {
        return $this->sequence->format('receipt', 'RCP-{year}-{seq:6}');
    }
}
