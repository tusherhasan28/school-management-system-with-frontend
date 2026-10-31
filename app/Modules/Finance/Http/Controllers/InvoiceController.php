<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academics\Services\AcademicOptions;
use App\Modules\Finance\Models\FeeCategory;
use App\Modules\Finance\Models\FeeInvoice;
use App\Modules\Finance\Services\BillingService;
use App\Modules\Finance\Services\FinanceDocuments;
use App\Modules\Finance\Services\FinancePresenter;
use App\Modules\Finance\Services\FinanceSettings;
use App\Modules\People\Models\Student;
use App\Support\Localization\Localized;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRule;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly BillingService $billing,
        private readonly FinancePresenter $present,
        private readonly TenantContext $tenant,
    ) {}

    public function index(Request $request, AcademicOptions $options, FinanceSettings $settings): Response
    {
        abort_unless($request->user()->can('fees.view'), 403);
        $f = $request->validate([
            'status' => ['nullable', Rule::in(['unpaid', 'partially_paid', 'paid', 'cancelled', 'overdue', 'open'])],
            'class' => ['nullable', 'integer'], 'section' => ['nullable', 'integer'],
            'period' => ['nullable', 'date_format:Y-m'], 'q' => ['nullable', 'string', 'max:100'],
        ]);
        $today = $this->today()->toDateString();

        $invoices = FeeInvoice::query()
            ->with(['student:id,school_id,full_name,admission_no', 'schoolClass:id,school_id,name', 'section:id,school_id,name'])
            ->when($f['status'] ?? null, fn ($q, $s) => match ($s) {
                'overdue' => $q->open()->whereDate('due_date', '<', $today),
                'open' => $q->open(),
                default => $q->where('status', $s),
            })
            ->when($f['class'] ?? null, fn ($q, $c) => $q->where('class_id', $c))
            ->when($f['section'] ?? null, fn ($q, $s) => $q->where('section_id', $s))
            ->when($f['period'] ?? null, fn ($q, $p) => $q->where('billing_period', $p))
            ->when($f['q'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q->where('invoice_no', 'like', "%{$term}%")
                ->orWhereHas('student', fn ($s) => $s->where('full_name', 'like', "%{$term}%")->orWhere('admission_no', 'like', "%{$term}%"))))
            ->orderByDesc('issue_date')->orderByDesc('id')
            ->paginate(25)->withQueryString()
            ->through(fn (FeeInvoice $i) => $this->present->invoice($i, $today));

        $nextMonth = $this->today()->addMonthNoOverflow()->format('Y-m');

        return Inertia::render('app/fees/invoices', [
            'invoices' => $invoices,
            'filters' => ['status' => $f['status'] ?? '', 'class' => $f['class'] ?? null, 'section' => $f['section'] ?? null, 'period' => $f['period'] ?? '', 'q' => $f['q'] ?? ''],
            'classes' => $options->classes(),
            'defaults' => [
                'period' => $this->today()->format('Y-m'),
                'due_date' => $this->today()->startOfMonth()->setDay((int) $settings->get('due_day'))->toDateString(),
                'next_period' => $nextMonth,
            ],
            'can' => ['manage' => $request->user()->can('fees.manage')],
        ]);
    }

    public function generate(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('fees.manage'), 403);
        $data = $request->validate([
            'period' => ['required', 'date_format:Y-m'],
            'class_id' => ['nullable', 'integer', TenantRule::exists('classes')],
            'due_date' => ['required', 'date'],
        ]);

        $counts = $this->billing->generate($data['period'], $data['class_id'] ?? null, CarbonImmutable::parse($data['due_date']), $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('finance.generated', $counts)]);

        return back();
    }

    public function show(Request $request, FeeInvoice $invoice): Response
    {
        abort_unless($request->user()->can('fees.view'), 403);
        $invoice->load(['student:id,school_id,full_name,name_bn,admission_no', 'schoolClass:id,school_id,name', 'section:id,school_id,name', 'items.category:id,school_id,name,name_bn',
            'allocations.payment' => fn ($q) => $q->with(['reversal', 'receiver:id,school_id,name'])]);

        return Inertia::render('app/fees/invoice', [
            'invoice' => $this->present->invoice($invoice, $this->today()->toDateString()) + [
                'notes' => $invoice->notes, 'cancel_reason' => $invoice->cancel_reason, 'cancelled_at' => $invoice->cancelled_at?->toIso8601String(),
                'items' => $invoice->items->map(fn ($i) => [
                    'id' => $i->id, 'kind' => $i->kind, 'description' => $i->description, 'category' => Localized::name($i->category?->name, $i->category?->name_bn),
                    'amount' => $i->amount, 'discount_amount' => $i->discount_amount, 'discount_note' => $i->discount_note, 'line_total' => $i->line_total,
                ]),
                'payments' => $invoice->allocations->map(fn ($a) => ['allocated' => $a->amount] + $this->present->payment($a->payment)),
            ],
            'can' => [
                'manage' => $request->user()->can('fees.manage') && $invoice->status !== 'cancelled',
                'collect' => $request->user()->can('fees.collect') && $invoice->balance() > 0,
            ],
        ]);
    }

    /** A one-off invoice for a student. */
    public function store(Request $request, Student $student): RedirectResponse
    {
        abort_unless($request->user()->can('fees.manage'), 403);
        $data = $request->validate([
            'due_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.fee_category_id' => ['nullable', 'integer', TenantRule::exists('fee_categories')],
            'items.*.description' => ['required', 'string', 'max:190'],
            'items.*.amount' => ['required', 'numeric', 'gt:0', 'max:9999999', 'decimal:0,2'],
        ]);
        $data['items'] = array_map(fn ($i) => ['amount' => Money::format(Money::parse((string) $i['amount']))] + $i, $data['items']);

        $invoice = $this->billing->createManual($student, $data['items'], CarbonImmutable::parse($data['due_date']), $data['notes'] ?? null, $request->user());

        return to_route('fees.invoices.show', $invoice);
    }

    public function addLine(Request $request, FeeInvoice $invoice): RedirectResponse
    {
        abort_unless($request->user()->can('fees.manage'), 403);
        $data = $request->validate([
            'kind' => ['required', Rule::in(['waiver', 'adjustment'])],
            'description' => ['required', 'string', 'min:3', 'max:190'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999', 'decimal:0,2'],
        ]);

        $this->billing->addLine($invoice, $data['kind'], $data['description'], (string) $data['amount'], $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('finance.line_added')]);

        return back();
    }

    public function cancel(Request $request, FeeInvoice $invoice): RedirectResponse
    {
        abort_unless($request->user()->can('fees.manage'), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']]);
        $this->billing->cancel($invoice, $data['reason'], $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('finance.invoice_cancelled_ok')]);

        return back();
    }

    public function print(Request $request, FeeInvoice $invoice, FinanceDocuments $documents): Response
    {
        abort_unless($request->user()->can('fees.view'), 403);

        return Inertia::render('app/fees/invoice-print', $documents->invoice($invoice));
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->tenant->require()->timezone)->startOfDay();
    }
}
