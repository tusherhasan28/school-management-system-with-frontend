<?php

namespace App\Modules\Finance\Jobs;

use App\Jobs\Middleware\EnsureTenantIsAccessible;
use App\Modules\Finance\Models\FeePayment;
use App\Modules\Finance\Services\OnlinePaymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Validation\ValidationException;

/**
 * For the dispatching school: ask the gateway about online payments that are
 * still pending (the payer may have paid but the callback never arrived), and
 * cancel ones that stayed unconfirmed for too long. One payment may be
 * checked on its own (after the payer returns from the gateway).
 */
class ReconcilePendingPayments implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public ?int $paymentId = null) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [new EnsureTenantIsAccessible];
    }

    public function handle(OnlinePaymentService $online): void
    {
        $query = FeePayment::query()->where('status', 'pending')->whereNotNull('gateway');

        if ($this->paymentId !== null) {
            $query->whereKey($this->paymentId);
        } else {
            $query->where('created_at', '<=', now()->subMinutes(config('finance.reconcile_after_minutes')));
        }

        $query->orderBy('id')->chunkById(100, function ($payments) use ($online) {
            foreach ($payments as $payment) {
                try {
                    $payment = $online->reconcile($payment);
                } catch (ValidationException) {
                    // Gateway no longer installed/enabled: leave for staff.
                }

                if ($payment->status === 'pending' && $payment->created_at->lt(now()->subHours(config('finance.expire_pending_after_hours')))) {
                    $payment->forceFill(['status' => 'cancelled'])->save();
                }
            }
        });
    }
}
