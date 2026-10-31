<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Gateways\GatewayNotification;
use App\Modules\Finance\Gateways\GatewayRegistry;
use App\Modules\Finance\Jobs\ReconcilePendingPayments;
use App\Modules\Finance\Models\FeeInvoice;
use App\Modules\Finance\Models\FeePayment;
use App\Modules\Finance\Models\PaymentGatewayAccount;
use App\Modules\Finance\Services\OnlinePaymentService;
use App\Modules\People\Models\Student;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesAcademicStructure;
use Tests\Concerns\CreatesFees;
use Tests\Support\FakeGateway;
use Tests\TenantTestCase;

/**
 * The online payment pipeline, exercised through a test-only adapter:
 * verified callbacks only, idempotent, browser return never trusted,
 * reconciliation for lost callbacks.
 */
class GatewayTest extends TenantTestCase
{
    use CreatesAcademicStructure, CreatesFees;

    private const SECRET = 'test-secret-value';

    private FeeInvoice $invoice;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $s = $this->academicStructure();
        $this->feePlan($s, $this->feeCategories()['tuition'], '1500.00', 'monthly', $s['class6']->id);
        $this->student = $this->makeStudent($s['session'], $s['class6'], $s['sectionA']);
        $this->actingAs($this->ownerOf($this->school))->post(route('fees.invoices.generate'), ['period' => '2026-06', 'due_date' => '2026-06-10']);
        $this->invoice = FeeInvoice::query()->sole();
        auth()->logout();
    }

    private function install(): void
    {
        app(GatewayRegistry::class)->register(new FakeGateway);
        PaymentGatewayAccount::create(['gateway' => 'fakepay', 'mode' => 'sandbox', 'is_enabled' => true, 'credentials' => ['merchant_id' => 'M1', 'secret' => self::SECRET]]);
    }

    private function start(): FeePayment
    {
        [$payment] = app(OnlinePaymentService::class)->start($this->invoice, 'fakepay', [
            'callback' => route('payments.callback', 'fakepay'), 'return' => route('payments.return', 'fakepay').'?ref={ref}',
        ]);

        return $payment;
    }

    private function notify(array $body, ?string $secret = self::SECRET): TestResponse
    {
        [$json, $sig] = FakeGateway::sign($body, $secret ?? 'wrong');

        return $this->call('POST', route('payments.callback', 'fakepay'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => $sig], $json);
    }

    public function test_no_gateway_is_installed_by_default(): void
    {
        $this->assertSame([], app(GatewayRegistry::class)->all());
        $this->post(route('payments.callback', 'bkash'), [])->assertNotFound();
        $this->post(route('payments.callback', 'sslcommerz'), [])->assertNotFound();
    }

    public function test_verified_callback_settles_the_invoice_once(): void
    {
        $this->install();
        $payment = $this->start();
        $this->assertSame(['pending', null, 'online'], [$payment->status, $payment->receipt_no, $payment->method]);

        $body = ['ref' => $payment->public_id, 'tx' => 'TX-1001', 'amount' => '1500.00', 'status' => 'successful', 'card_number' => '4111', 'merchant_secret' => 'zzz'];
        $this->notify($body)->assertOk();
        $this->notify($body)->assertOk();             // provider retries
        $this->notify($body + ['x' => 1])->assertOk(); // and again, differently formatted

        $payment->refresh();
        $this->assertSame(['successful', 'TX-1001'], [$payment->status, $payment->gateway_transaction_id]);
        $this->assertNotNull($payment->receipt_no);
        $this->assertSame(1, $payment->allocations()->count());
        $this->assertSame(['paid', '1500.00'], [$this->invoice->refresh()->status, $this->invoice->paid_total]);
        $this->assertArrayNotHasKey('card_number', $payment->gateway_payload);
        $this->assertArrayNotHasKey('merchant_secret', $payment->gateway_payload);
    }

    public function test_bad_signature_wrong_amount_and_failure_do_not_settle(): void
    {
        $this->install();
        $payment = $this->start();

        $this->notify(['ref' => $payment->public_id, 'tx' => 'T1', 'amount' => '1500.00', 'status' => 'successful'], null)->assertStatus(400);
        $this->assertSame('pending', $payment->refresh()->status);

        $this->notify(['ref' => $payment->public_id, 'tx' => 'T1', 'amount' => '15.00', 'status' => 'successful'])->assertOk();
        $this->assertSame(['pending', 'amount_mismatch'], [$payment->refresh()->status, $payment->gateway_payload['_review']]);

        $this->notify(['ref' => $payment->public_id, 'tx' => 'T1', 'status' => 'failed'])->assertOk();
        $this->assertSame('failed', $payment->refresh()->status);
        $this->assertSame('unpaid', $this->invoice->refresh()->status);

        $this->notify(['ref' => $payment->public_id, 'tx' => 'T1', 'amount' => '1500.00', 'status' => 'successful'])->assertOk();
        $this->assertSame('failed', $payment->refresh()->status, 'A settled outcome is final.');
    }

    public function test_one_gateway_transaction_cannot_pay_twice(): void
    {
        $this->install();
        $first = $this->start();
        $this->notify(['ref' => $first->public_id, 'tx' => 'SAME', 'amount' => '1500.00', 'status' => 'successful'])->assertOk();

        $this->actingAs($this->ownerOf($this->school))->post(route('fees.invoices.generate'), ['period' => '2026-07', 'due_date' => '2026-07-10']);
        $this->invoice = FeeInvoice::query()->where('billing_period', '2026-07')->sole();
        $second = $this->start();
        $this->notify(['ref' => $second->public_id, 'tx' => 'SAME', 'amount' => '1500.00', 'status' => 'successful'])->assertOk();

        $this->assertSame('pending', $second->refresh()->status);
        $this->assertSame(1, FeePayment::query()->where('status', 'successful')->count());
    }

    public function test_the_browser_return_never_marks_a_payment_paid(): void
    {
        Queue::fake();
        $this->install();
        $payment = $this->start();

        $this->get(route('payments.return', 'fakepay').'?ref='.$payment->public_id.'&status=successful&amount=1500.00')->assertOk();

        $this->assertSame('pending', $payment->refresh()->status);
        Queue::assertPushed(ReconcilePendingPayments::class, fn ($job) => $job->paymentId === $payment->id);
    }

    public function test_reconciliation_settles_lost_callbacks_and_expires_old_ones(): void
    {
        $this->install();
        $paid = $this->start();
        $stale = FeePayment::query()->create(['student_id' => $this->student->id, 'method' => 'online', 'gateway' => 'fakepay', 'amount' => '1500.00',
            'intended_allocations' => [['invoice_id' => $this->invoice->id, 'amount' => '1500.00']]]);
        DB::table('fee_payments')->whereIn('id', [$paid->id, $stale->id])->update(['created_at' => now()->subHour()]);
        DB::table('fee_payments')->where('id', $stale->id)->update(['created_at' => now()->subDays(3)]);

        FakeGateway::$queryAnswers[$paid->public_id] = new GatewayNotification($paid->public_id, 'TX-Q1', '1500.00', 'successful');
        ReconcilePendingPayments::dispatchSync();

        $this->assertSame('successful', $paid->refresh()->status);
        $this->assertSame('cancelled', $stale->refresh()->status);
        $this->assertSame('paid', $this->invoice->refresh()->status);
    }

    public function test_cash_taken_meanwhile_leaves_the_excess_unallocated(): void
    {
        $this->install();
        $payment = $this->start();
        $this->actingAs($this->ownerOf($this->school))->post(route('fees.students.payments.store', $this->student), [
            'amount' => '1000', 'method' => 'cash', 'idempotency_key' => str_repeat('k', 20),
        ])->assertSessionHasNoErrors();
        auth()->logout();

        $this->notify(['ref' => $payment->public_id, 'tx' => 'TX-9', 'amount' => '1500.00', 'status' => 'successful'])->assertOk();

        // Only the 500.00 still owed is allocated; the other 1000.00 stays unallocated for a refund.
        $this->assertSame(['500.00'], $payment->refresh()->allocations()->pluck('amount')->all());
        $this->assertSame(['paid', '1500.00'], [$this->invoice->refresh()->status, $this->invoice->paid_total]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'finance.online_payment_succeeded']);
    }

    public function test_gateway_credentials_are_encrypted_and_hidden(): void
    {
        $this->install();
        $raw = DB::table('payment_gateway_accounts')->value('credentials');

        $this->assertStringNotContainsString(self::SECRET, $raw);
        $this->assertArrayNotHasKey('credentials', PaymentGatewayAccount::query()->first()->toArray());
    }
}
