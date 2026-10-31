<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Gateways\GatewayRegistry;
use App\Modules\Finance\Gateways\InvalidGatewaySignature;
use App\Modules\Finance\Jobs\ReconcilePendingPayments;
use App\Modules\Finance\Models\FeePayment;
use App\Modules\Finance\Models\PaymentGatewayAccount;
use App\Modules\Finance\Services\OnlinePaymentService;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public endpoints on the school's host for online payments.
 *
 * callback: server-to-server notification. Only a signature the adapter
 *           verified can change a payment; repeats are harmless.
 * return:   where the payer's browser lands. It NEVER changes a payment; it
 *           shows the current status and asks the gateway (server-side) to
 *           confirm via the reconciliation job.
 */
class GatewayController extends Controller
{
    public function callback(Request $request, string $gateway, GatewayRegistry $registry, OnlinePaymentService $online, AuditLogger $audit): HttpResponse
    {
        $adapter = $registry->get($gateway);
        $account = $adapter ? PaymentGatewayAccount::query()->where('gateway', $gateway)->where('is_enabled', true)->first() : null;
        abort_if($adapter === null || $account === null, 404);

        try {
            $notification = $adapter->parseCallback($request, $account);
        } catch (InvalidGatewaySignature) {
            $audit->tenant('finance.gateway_callback_rejected', null, [], ['gateway' => $gateway, 'ip' => $request->ip()]);

            return response('Invalid signature', 400);
        }

        $online->apply($gateway, $notification);

        return response('OK', 200);
    }

    public function return(Request $request, string $gateway, TenantContext $tenant): Response
    {
        $payment = FeePayment::query()->where('gateway', $gateway)->where('public_id', (string) $request->query('ref'))->first();

        if ($payment?->status === 'pending') {
            ReconcilePendingPayments::dispatch($payment->id);
        }

        return Inertia::render('tenant/public/payment-return', [
            'school' => $tenant->require()->only(['name', 'name_bn']),
            'payment' => $payment ? ['status' => $payment->status, 'amount' => $payment->amount, 'receipt_no' => $payment->receipt_no] : null,
        ]);
    }
}
