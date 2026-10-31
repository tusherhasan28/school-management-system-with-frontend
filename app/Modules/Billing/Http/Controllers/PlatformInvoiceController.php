<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Models\PlatformInvoice;
use App\Modules\Billing\Services\BillingPresenter;
use App\Modules\Billing\Services\SubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Platform: invoices to schools and their payments. */
class PlatformInvoiceController extends Controller
{
    public function __construct(private readonly BillingPresenter $present, private readonly SubscriptionService $billing) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in(['unpaid', 'overdue', 'paid', 'void'])], 'search' => ['nullable', 'string', 'max:100']]);

        $invoices = PlatformInvoice::query()->with('school:id,name')
            ->when($filters['status'] ?? null, fn ($q, $s) => $s === 'overdue' ? $q->overdue() : $q->where('status', $s))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('invoice_number', 'like', "%{$s}%")->orWhereHas('school', fn ($q) => $q->where('name', 'like', "%{$s}%"))))
            ->orderByDesc('issued_at')->orderByDesc('id')
            ->paginate(25)->withQueryString()
            ->through(fn (PlatformInvoice $i) => $this->present->invoice($i));

        return Inertia::render('platform/invoices/index', [
            'invoices' => $invoices,
            'filters' => ['status' => $filters['status'] ?? '', 'search' => $filters['search'] ?? ''],
            'totals' => [
                'unpaid' => (string) PlatformInvoice::query()->where('status', 'unpaid')->sum('total'),
                'overdue' => (string) PlatformInvoice::query()->overdue()->sum('total'),
                'paid_this_month' => (string) PlatformInvoice::query()->where('status', 'paid')->where('paid_at', '>=', now()->startOfMonth())->sum('total'),
            ],
        ]);
    }

    public function show(Request $request, PlatformInvoice $invoice): Response
    {
        $invoice->load(['school', 'payments.recorder:id,name']);

        return Inertia::render('platform/invoices/show', [
            'invoice' => $this->present->invoice($invoice) + ['subtotal' => $invoice->subtotal, 'discount' => $invoice->discount, 'tax' => $invoice->tax],
            'school' => $invoice->school->only(['id', 'name', 'name_bn', 'address', 'phone', 'email', 'slug']),
            'payments' => $invoice->payments->map(fn ($p) => $this->present->payment($p)),
            'instructions' => config('billing.payment_instructions'),
            'can' => ['manage' => $request->user('platform')->can('billing.manage')],
        ]);
    }

    public function pay(Request $request, PlatformInvoice $invoice): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'decimal:0,2'],
            'method' => ['required', Rule::in(['bank', 'mobile_banking', 'cash', 'cheque'])],
            'transaction_id' => ['nullable', 'required_if:method,bank,mobile_banking,cheque', 'string', 'max:150', Rule::unique('platform_payments', 'transaction_id')->where('gateway', 'manual')],
            'received_on' => ['nullable', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        $data['received_at'] = isset($data['received_on']) ? CarbonImmutable::parse($data['received_on'], 'Asia/Dhaka')->setTime(12, 0)->utc() : now();

        $this->billing->recordPayment($invoice, $data);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('billing.payment_recorded')]);

        return back();
    }

    public function void(Request $request, PlatformInvoice $invoice): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->billing->voidInvoice($invoice, $data['reason']);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('billing.saved')]);

        return back();
    }
}
