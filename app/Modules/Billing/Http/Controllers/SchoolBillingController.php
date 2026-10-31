<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Services\SubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Platform: a school's subscription (assign, suspend, reactivate, cancel, trial) and manual invoices. */
class SchoolBillingController extends Controller
{
    public function __construct(private readonly SubscriptionService $billing) {}

    public function assign(Request $request, School $school): RedirectResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')->where('is_active', true)],
            'billing_cycle' => ['required', Rule::in(['monthly', 'yearly'])],
            'trial' => ['boolean'],
            'auto_renew' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $this->billing->assign($school, Plan::query()->findOrFail($data['plan_id']), $data['billing_cycle'], (bool) ($data['trial'] ?? false),
            (bool) ($data['auto_renew'] ?? true), null, $data['notes'] ?? null);

        return $this->done(__('billing.assigned'));
    }

    public function suspend(Request $request, School $school): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);
        $this->billing->suspend($this->current($school), $data['reason']);

        return $this->done(__('billing.saved'));
    }

    public function reactivate(School $school): RedirectResponse
    {
        $this->billing->reactivate($this->current($school));

        return $this->done(__('billing.saved'));
    }

    public function cancel(Request $request, School $school): RedirectResponse
    {
        $data = $request->validate(['at_period_end' => ['boolean'], 'reason' => ['nullable', 'string', 'max:200']]);
        $this->billing->cancel($this->current($school), (bool) ($data['at_period_end'] ?? true), $data['reason'] ?? null);

        return $this->done(__('billing.saved'));
    }

    public function extendTrial(Request $request, School $school): RedirectResponse
    {
        $data = $request->validate(['days' => ['required', 'integer', 'min:1', 'max:90']]);
        $this->billing->extendTrial($this->current($school), (int) $data['days']);

        return $this->done(__('billing.saved'));
    }

    public function invoice(Request $request, School $school): RedirectResponse
    {
        $data = $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:1', 'max:99999999', 'decimal:0,2'],
            'due_on' => ['required', 'date', 'after_or_equal:today'],
        ]);
        $this->billing->issueManualInvoice($school, $data['description'], number_format((float) $data['amount'], 2, '.', ''), CarbonImmutable::parse($data['due_on'], 'Asia/Dhaka')->endOfDay()->utc());

        return $this->done(__('billing.invoice_issued'));
    }

    private function current(School $school): Subscription
    {
        return Subscription::query()->where('school_id', $school->id)->live()->latest('id')->firstOrFail();
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
