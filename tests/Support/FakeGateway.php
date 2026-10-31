<?php

namespace Tests\Support;

use App\Modules\Finance\Gateways\GatewayCheckout;
use App\Modules\Finance\Gateways\GatewayNotification;
use App\Modules\Finance\Gateways\InvalidGatewaySignature;
use App\Modules\Finance\Gateways\PaymentGateway;
use App\Modules\Finance\Models\FeePayment;
use App\Modules\Finance\Models\PaymentGatewayAccount;
use Illuminate\Http\Request;

/**
 * Test-only gateway: callbacks are JSON signed with HMAC-SHA256 of the raw
 * body using the account's "secret". Never registered in the application.
 */
class FakeGateway implements PaymentGateway
{
    /** @var array<string, GatewayNotification> what query() answers, by public id */
    public static array $queryAnswers = [];

    public function key(): string
    {
        return 'fakepay';
    }

    public function label(): string
    {
        return 'FakePay';
    }

    public function credentialFields(): array
    {
        return [['key' => 'merchant_id', 'label' => 'Merchant ID', 'secret' => false], ['key' => 'secret', 'label' => 'Secret', 'secret' => true]];
    }

    public function initiate(FeePayment $payment, PaymentGatewayAccount $account, array $urls): GatewayCheckout
    {
        return new GatewayCheckout('https://fakepay.test/checkout', 'POST', ['ref' => $payment->public_id, 'amount' => $payment->amount, 'callback' => $urls['callback'], 'return' => $urls['return']]);
    }

    public function parseCallback(Request $request, PaymentGatewayAccount $account): GatewayNotification
    {
        $expected = hash_hmac('sha256', $request->getContent(), $account->credentials['secret']);

        if (! hash_equals($expected, (string) $request->header('X-Signature'))) {
            throw new InvalidGatewaySignature;
        }

        $data = json_decode($request->getContent(), true);

        return new GatewayNotification($data['ref'], $data['tx'] ?? null, $data['amount'] ?? null, $data['status'], $data);
    }

    public function query(FeePayment $payment, PaymentGatewayAccount $account): GatewayNotification
    {
        return self::$queryAnswers[$payment->public_id] ?? new GatewayNotification($payment->public_id, null, null, 'pending');
    }

    public static function sign(array $body, string $secret): array
    {
        $json = json_encode($body);

        return [$json, hash_hmac('sha256', $json, $secret)];
    }
}
