<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Gateways\GatewayCheckout;
use App\Modules\Finance\Gateways\GatewayNotification;
use App\Modules\Finance\Gateways\GatewayRegistry;
use App\Modules\Finance\Gateways\PayloadSanitizer;
use App\Modules\Finance\Gateways\PaymentGateway;
use App\Modules\Finance\Models\FeeInvoice;
use App\Modules\Finance\Models\FeePayment;
use App\Modules\Finance\Models\PaymentGatewayAccount;
use App\Services\Audit\AuditLogger;
use App\Support\Money\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Online payments through an installed gateway adapter.
 *
 *  start()  → a pending payment and a checkout URL for the payer.
 *  apply()  → the only way a pending online payment becomes successful: a
 *             notification that the adapter verified (signed callback) or
 *             fetched itself (query). Idempotent: repeating a notification
 *             changes nothing; the gateway transaction id is unique.
 */
class OnlinePaymentService
{
    public function __construct(
        private readonly GatewayRegistry $gateways,
        private readonly PaymentService $payments,
        private readonly AuditLogger $audit,
    ) {}

    /** Enabled gateways for the current school, as [key => label]. */
    public function available(): array
    {
        $enabled = PaymentGatewayAccount::query()->where('is_enabled', true)->pluck('gateway')->all();

        return collect($this->gateways->all())->filter(fn ($g, $key) => in_array($key, $enabled, true))->map->label()->all();
    }

    /** @return array{0: PaymentGateway, 1: PaymentGatewayAccount} */
    public function resolve(string $key): array
    {
        $gateway = $this->gateways->get($key);
        $account = $gateway ? PaymentGatewayAccount::query()->where('gateway', $key)->where('is_enabled', true)->first() : null;

        if ($gateway === null || $account === null) {
            throw ValidationException::withMessages(['gateway' => __('finance.gateway_unavailable')]);
        }

        return [$gateway, $account];
    }

    /**
     * @param  array{callback: string, return: string}  $urls
     * @return array{0: FeePayment, 1: GatewayCheckout}
     */
    public function start(FeeInvoice $invoice, string $gatewayKey, array $urls): array
    {
        [$gateway, $account] = $this->resolve($gatewayKey);
        $balance = $invoice->balance();

        if ($balance <= 0) {
            throw ValidationException::withMessages(['invoice' => __('finance.nothing_due')]);
        }

        $payment = new FeePayment([
            'student_id' => $invoice->student_id, 'method' => 'online', 'gateway' => $gatewayKey, 'amount' => Money::format($balance),
            'intended_allocations' => [['invoice_id' => $invoice->id, 'amount' => Money::format($balance)]],
        ]);
        $payment->save();

        $checkout = $gateway->initiate($payment, $account, [
            'callback' => str_replace('{ref}', $payment->public_id, $urls['callback']),
            'return' => str_replace('{ref}', $payment->public_id, $urls['return']),
        ]);
        $this->audit->tenant('finance.online_payment_started', $payment, [], ['gateway' => $gatewayKey, 'amount' => $payment->amount]);

        return [$payment, $checkout];
    }

    /**
     * Apply a verified notification. Returns the payment (or null if unknown).
     */
    public function apply(string $gatewayKey, GatewayNotification $n): ?FeePayment
    {
        try {
            return DB::transaction(fn () => $this->applyLocked($gatewayKey, $n));
        } catch (UniqueConstraintViolationException) {
            // The same gateway transaction is already recorded on another payment.
            Log::warning('Duplicate gateway transaction ignored', ['gateway' => $gatewayKey, 'public_id' => $n->publicId]);

            return FeePayment::query()->where('public_id', $n->publicId)->first();
        }
    }

    /** Ask the gateway about a pending payment and apply the answer. */
    public function reconcile(FeePayment $payment): FeePayment
    {
        if ($payment->status !== 'pending' || $payment->gateway === null) {
            return $payment;
        }

        [$gateway, $account] = $this->resolve($payment->gateway);
        $payment->forceFill(['reconcile_attempts' => $payment->reconcile_attempts + 1])->save();

        return $this->apply($payment->gateway, $gateway->query($payment, $account)) ?? $payment;
    }

    private function applyLocked(string $gatewayKey, GatewayNotification $n): ?FeePayment
    {
        $payment = FeePayment::query()->where('public_id', $n->publicId)->where('gateway', $gatewayKey)->lockForUpdate()->first();

        if ($payment === null) {
            Log::warning('Gateway notification for unknown payment', ['gateway' => $gatewayKey, 'public_id' => $n->publicId]);

            return null;
        }

        if ($payment->status !== 'pending') {
            return $payment; // already settled: repeated callbacks change nothing
        }

        $payload = PayloadSanitizer::clean($n->payload);

        if ($n->status === 'failed') {
            $payment->forceFill(['status' => 'failed', 'gateway_transaction_id' => $n->transactionId, 'gateway_payload' => $payload])->save();
            $this->audit->tenant('finance.online_payment_failed', $payment, [], ['gateway' => $gatewayKey]);

            return $payment;
        }

        if ($n->status !== 'successful') {
            $payment->forceFill(['gateway_payload' => $payload])->save();

            return $payment;
        }

        if ($n->amount === null || Money::parse($n->amount) !== Money::parse($payment->amount)) {
            // Do not settle a payment for a different amount; keep it pending for review.
            $payment->forceFill(['gateway_payload' => $payload + ['_review' => 'amount_mismatch', '_reported_amount' => $n->amount]])->save();
            $this->audit->tenant('finance.online_payment_amount_mismatch', $payment, [], ['expected' => $payment->amount, 'reported' => $n->amount]);

            return $payment;
        }

        $payment->forceFill([
            'status' => 'successful', 'gateway_transaction_id' => $n->transactionId, 'gateway_payload' => $payload,
            'receipt_no' => $this->payments->receiptNo(), 'received_at' => now(),
        ])->save();

        // Settle the intended invoices, limited to what is still owed (staff may
        // have collected cash meanwhile); anything left over stays unallocated
        // and is flagged on the payment for a refund.
        $requested = collect($payment->intended_allocations ?? [])->mapWithKeys(fn ($a) => [$a['invoice_id'] => Money::parse($a['amount'])]);
        $plan = [];
        $open = FeeInvoice::query()->whereIn('id', $requested->keys())->lockForUpdate()->get()->keyBy('id');

        foreach ($requested as $invoiceId => $paisa) {
            $invoice = $open->get($invoiceId);
            $plan[$invoiceId] = $invoice ? min($paisa, max(0, app(InvoiceLedger::class)->balance($invoice))) : 0;
        }

        $this->payments->allocate($payment, $plan);
        $this->audit->tenant('finance.online_payment_succeeded', $payment, [], [
            'gateway' => $gatewayKey, 'receipt_no' => $payment->receipt_no, 'amount' => $payment->amount,
            'unallocated' => Money::format(Money::parse($payment->amount) - array_sum($plan)),
        ]);

        return $payment;
    }
}
