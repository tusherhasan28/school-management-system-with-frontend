<?php

namespace App\Modules\Finance\Gateways;

use App\Modules\Finance\Models\FeePayment;
use App\Modules\Finance\Models\PaymentGatewayAccount;
use Illuminate\Http\Request;

/**
 * Contract every online payment provider adapter implements.
 *
 * Rules for implementers (see docs/architecture.md, "Payments"):
 *  - Follow the provider's current official documentation; never guess endpoints.
 *  - parseCallback() MUST verify the provider's signature/hash and throw
 *    InvalidGatewaySignature when it does not match.
 *  - Success is only ever trusted from a verified callback or from query()
 *    (server-to-server). The browser return URL is never trusted.
 *  - Never put credentials or signatures in the returned payload; it is stored.
 */
interface PaymentGateway
{
    /** Short stable key, e.g. "sslcommerz". */
    public function key(): string;

    public function label(): string;

    /**
     * Credential fields the school fills in.
     *
     * @return list<array{key: string, label: string, secret: bool}>
     */
    public function credentialFields(): array;

    /**
     * Start a checkout for a pending payment.
     *
     * @param  array{callback: string, return: string}  $urls
     */
    public function initiate(FeePayment $payment, PaymentGatewayAccount $account, array $urls): GatewayCheckout;

    /**
     * Verify and read a server-to-server notification.
     *
     * @throws InvalidGatewaySignature
     */
    public function parseCallback(Request $request, PaymentGatewayAccount $account): GatewayNotification;

    /** Ask the provider for the current status of a payment (reconciliation). */
    public function query(FeePayment $payment, PaymentGatewayAccount $account): GatewayNotification;
}
