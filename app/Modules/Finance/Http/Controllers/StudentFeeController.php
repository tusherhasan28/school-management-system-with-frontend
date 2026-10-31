<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academics\Services\AcademicOptions;
use App\Modules\Finance\Models\FeeCategory;
use App\Modules\Finance\Models\FeeDiscount;
use App\Modules\Finance\Models\FeeInvoice;
use App\Modules\Finance\Models\FeePayment;
use App\Modules\Finance\Models\FeeStructure;
use App\Modules\Finance\Models\StudentFeeAssignment;
use App\Modules\Finance\Services\FinancePresenter;
use App\Modules\People\Models\Student;
use App\Services\Audit\AuditLogger;
use App\Support\Localization\Localized;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRule;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** One student's fee account: invoices, payments, opt-in fees, discounts, collection. */
class StudentFeeController extends Controller
{
    public function __construct(
        private readonly FinancePresenter $present,
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    public function show(Request $request, Student $student, AcademicOptions $options): Response
    {
        $user = $request->user();
        abort_unless($user->can('fees.view') || $user->can('fees.collect'), 403);
        $today = CarbonImmutable::now($this->tenant->require()->timezone)->toDateString();
        $sessionId = $options->currentSessionId();
        $enrollment = $student->enrollments()->with(['schoolClass:id,school_id,name', 'section:id,school_id,name'])->where('academic_session_id', $sessionId)->first();

        $invoices = FeeInvoice::query()->where('student_id', $student->id)->orderByDesc('due_date')->orderByDesc('id')->get();
        $open = $invoices->filter(fn ($i) => in_array($i->status, ['unpaid', 'partially_paid'], true))->sortBy(fn ($i) => [$i->due_date->toDateString(), $i->id]);

        return Inertia::render('app/fees/student', [
            'student' => [
                'id' => $student->id, 'name' => $student->full_name, 'name_bn' => $student->name_bn, 'admission_no' => $student->admission_no,
                'class' => $enrollment?->schoolClass?->name, 'section' => $enrollment?->section?->name, 'roll' => $enrollment?->roll_no,
            ],
            'summary' => [
                'due' => Money::format($open->sum(fn ($i) => $i->balance())),
                'overdue' => Money::format($open->filter(fn ($i) => $i->isOverdue($today))->sum(fn ($i) => $i->balance())),
                'paid_this_session' => Money::format(Money::sum(FeePayment::query()->where('student_id', $student->id)->where('status', 'successful')->whereDoesntHave('reversal')
                    ->whereHas('allocations.invoice', fn ($q) => $q->where('academic_session_id', $sessionId))->pluck('amount'))),
            ],
            'invoices' => $invoices->map(fn ($i) => $this->present->invoice($i, $today))->values(),
            'openInvoices' => $open->map(fn ($i) => ['id' => $i->id, 'invoice_no' => $i->invoice_no, 'due_date' => $i->due_date->toDateString(), 'balance' => Money::format($i->balance()), 'period' => $i->billing_period])->values(),
            'payments' => FeePayment::query()->with(['receiver:id,school_id,name', 'reversal'])->where('student_id', $student->id)->orderByDesc('id')->limit(50)->get()
                ->map(fn ($p) => $this->present->payment($p)),
            'assignments' => StudentFeeAssignment::query()->with('structure.category')->where('student_id', $student->id)->get()->map(fn ($a) => [
                'id' => $a->id, 'structure' => $a->structure?->name, 'category' => Localized::name($a->structure?->category?->name, $a->structure?->category?->name_bn),
                'default_amount' => $a->structure?->amount, 'custom_amount' => $a->custom_amount, 'note' => $a->note, 'is_active' => $a->is_active, 'applies_to' => $a->structure?->applies_to,
            ]),
            'discounts' => FeeDiscount::query()->with('category:id,school_id,name,name_bn')->where('student_id', $student->id)->where('academic_session_id', $sessionId)->get()->map(fn ($d) => [
                'id' => $d->id, 'name' => $d->name, 'kind' => $d->kind, 'value' => $d->value, 'is_active' => $d->is_active,
                'category' => $d->category ? Localized::name($d->category->name, $d->category->name_bn) : null,
            ]),
            'options' => [
                'structures' => FeeStructure::query()->where('academic_session_id', $sessionId)->where('is_active', true)
                    ->where(fn ($q) => $q->whereNull('class_id')->orWhere('class_id', $enrollment?->class_id))->orderBy('name')->get(['id', 'name', 'amount', 'applies_to']),
                'categories' => FeeCategory::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name', 'name_bn'])->map(fn ($c) => ['id' => $c->id, 'name' => Localized::name($c->name, $c->name_bn)]),
            ],
            'today' => $today,
            'can' => ['collect' => $user->can('fees.collect'), 'manage' => $user->can('fees.manage')],
        ]);
    }

    public function storeAssignment(Request $request, Student $student): RedirectResponse
    {
        abort_unless($request->user()->can('fees.manage'), 403);
        $data = $request->validate([
            'fee_structure_id' => ['required', 'integer', TenantRule::exists('fee_structures')],
            'custom_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999', 'decimal:0,2'],
            'note' => ['nullable', 'string', 'max:190'],
        ]);

        $structure = FeeStructure::query()->findOrFail($data['fee_structure_id']);

        if ($structure->applies_to === 'class' && ($data['custom_amount'] ?? null) === null) {
            throw ValidationException::withMessages(['custom_amount' => __('finance.override_needs_amount')]);
        }

        $assignment = StudentFeeAssignment::query()->updateOrCreate(
            ['student_id' => $student->id, 'fee_structure_id' => $structure->id],
            ['custom_amount' => isset($data['custom_amount']) ? Money::format(Money::parse((string) $data['custom_amount'])) : null, 'note' => $data['note'] ?? null, 'is_active' => true],
        );
        $this->audit->tenant('finance.assignment_saved', $assignment, [], $assignment->only(['student_id', 'fee_structure_id', 'custom_amount']));

        return back();
    }

    public function toggleAssignment(Request $request, StudentFeeAssignment $assignment): RedirectResponse
    {
        abort_unless($request->user()->can('fees.manage'), 403);
        $assignment->update(['is_active' => ! $assignment->is_active]);
        $this->audit->tenant('finance.assignment_toggled', $assignment, [], ['is_active' => $assignment->is_active]);

        return back();
    }

    public function storeDiscount(Request $request, Student $student, AcademicOptions $options): RedirectResponse
    {
        abort_unless($request->user()->can('fees.manage'), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::in(['percent', 'fixed'])],
            'value' => ['required', 'numeric', 'gt:0', 'max:9999999', 'decimal:0,2'],
            'fee_category_id' => ['nullable', 'integer', TenantRule::exists('fee_categories')],
        ]);

        $value = Money::parse((string) $data['value']);

        if ($data['kind'] === 'percent' && $value > 10000) {
            throw ValidationException::withMessages(['value' => __('finance.percent_max')]);
        }

        $discount = FeeDiscount::create([
            'student_id' => $student->id, 'academic_session_id' => $options->currentSessionId() ?? abort(422),
            'name' => $data['name'], 'kind' => $data['kind'], 'value' => Money::format($value),
            'fee_category_id' => $data['fee_category_id'] ?? null, 'created_by' => $request->user()->id,
        ]);
        $this->audit->tenant('finance.discount_created', $discount, [], $discount->only(['student_id', 'name', 'kind', 'value', 'fee_category_id']));

        return back();
    }

    public function toggleDiscount(Request $request, FeeDiscount $discount): RedirectResponse
    {
        abort_unless($request->user()->can('fees.manage'), 403);
        $discount->update(['is_active' => ! $discount->is_active]);
        $this->audit->tenant('finance.discount_toggled', $discount, [], ['is_active' => $discount->is_active]);

        return back();
    }
}
