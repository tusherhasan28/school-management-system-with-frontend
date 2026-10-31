<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\FeePayment;
use App\Modules\Finance\Services\FinanceDocuments;
use App\Modules\Finance\Services\FinancePresenter;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\People\Models\Student;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly FinancePresenter $present,
        private readonly TenantContext $tenant,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('fees.view'), 403);
        $tz = $this->tenant->require()->timezone;
        $f = $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
            'method' => ['nullable', Rule::in(FeePayment::METHODS)], 'status' => ['nullable', Rule::in(['successful', 'pending', 'failed', 'cancelled', 'reversed'])],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $from = CarbonImmutable::parse($f['from'] ?? CarbonImmutable::now($tz)->startOfMonth()->toDateString(), $tz)->startOfDay();
        $to = CarbonImmutable::parse($f['to'] ?? CarbonImmutable::now($tz)->toDateString(), $tz)->endOfDay();

        $payments = FeePayment::query()
            ->with(['student:id,school_id,full_name,admission_no', 'receiver:id,school_id,name', 'reversal'])
            ->where(fn ($q) => $q->whereBetween('received_at', [$from->utc(), $to->utc()])->orWhere(fn ($q) => $q->whereNull('received_at')->whereBetween('created_at', [$from->utc(), $to->utc()])))
            ->when($f['method'] ?? null, fn ($q, $m) => $q->where('method', $m))
            ->when($f['status'] ?? null, fn ($q, $s) => $s === 'reversed' ? $q->whereHas('reversal') : $q->where('status', $s)->when($s === 'successful', fn ($q) => $q->whereDoesntHave('reversal')))
            ->when($f['q'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q->where('receipt_no', 'like', "%{$term}%")->orWhere('reference', 'like', "%{$term}%")
                ->orWhereHas('student', fn ($s) => $s->where('full_name', 'like', "%{$term}%")->orWhere('admission_no', 'like', "%{$term}%"))))
            ->orderByDesc('received_at')->orderByDesc('id')
            ->paginate(25)->withQueryString()
            ->through(fn (FeePayment $p) => $this->present->payment($p));

        return Inertia::render('app/fees/payments', [
            'payments' => $payments,
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'method' => $f['method'] ?? '', 'status' => $f['status'] ?? '', 'q' => $f['q'] ?? ''],
            'can' => ['refund' => $request->user()->can('fees.refund')],
        ]);
    }

    public function store(Request $request, Student $student): RedirectResponse
    {
        abort_unless($request->user()->can('fees.collect'), 403);
        $tz = $this->tenant->require()->timezone;
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999', 'decimal:0,2'],
            'method' => ['required', Rule::in(['cash', 'bank', 'cheque', 'mobile_banking'])],
            'reference' => [Rule::requiredIf(fn () => in_array($request->input('method'), ['bank', 'cheque', 'mobile_banking'], true)), 'nullable', 'string', 'max:120'],
            'received_on' => ['nullable', 'date', 'before_or_equal:'.CarbonImmutable::now($tz)->toDateString()],
            'notes' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:64'],
            'allocations' => ['nullable', 'array'],
            'allocations.*' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
        ]);

        $allocations = isset($data['allocations']) ? array_filter(array_map(fn ($v) => $v === null ? null : Money::format(Money::parse((string) $v)), $data['allocations'])) : null;
        $receivedAt = isset($data['received_on']) && $data['received_on'] !== CarbonImmutable::now($tz)->toDateString()
            ? CarbonImmutable::parse($data['received_on'], $tz)->setTime(12, 0)
            : now();

        $payment = $this->payments->record($student, [
            'amount' => Money::format(Money::parse((string) $data['amount'])), 'method' => $data['method'], 'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null, 'received_at' => $receivedAt, 'idempotency_key' => $data['idempotency_key'],
        ], $allocations ?: null, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('finance.payment_recorded', ['receipt' => $payment->receipt_no])]);
        Inertia::flash('receipt', $payment->id);

        return back();
    }

    public function reverse(Request $request, FeePayment $payment): RedirectResponse
    {
        abort_unless($request->user()->can('fees.refund'), 403);
        $data = $request->validate([
            'type' => ['required', Rule::in(['reversal', 'refund'])],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
            'refund_method' => ['nullable', 'required_if:type,refund', Rule::in(['cash', 'bank', 'cheque', 'mobile_banking'])],
            'refund_reference' => ['nullable', 'string', 'max:120'],
        ]);

        $this->payments->reverse($payment, $data, $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('finance.payment_'.$data['type'].'_done')]);

        return back();
    }

    public function receipt(Request $request, FeePayment $payment, FinanceDocuments $documents): Response
    {
        abort_unless($request->user()->can('fees.view') || $request->user()->can('fees.collect'), 403);
        abort_unless($payment->status === 'successful', 404);

        return Inertia::render('app/fees/receipt', $documents->receipt($payment));
    }
}
