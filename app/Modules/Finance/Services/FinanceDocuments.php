<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\FeeInvoice;
use App\Modules\Finance\Models\FeePayment;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;

/**
 * Props for the printable invoice and receipt, shared by staff screens and
 * the family portals. Callers decide who may see which document.
 */
class FinanceDocuments
{
    public function __construct(
        private readonly FinancePresenter $present,
        private readonly FinanceSettings $settings,
        private readonly TenantContext $tenant,
    ) {}

    /** @return array<string, mixed> */
    public function invoice(FeeInvoice $invoice): array
    {
        $invoice->load(['student' => fn ($q) => $q->select(['id', 'school_id', 'full_name', 'name_bn', 'admission_no'])->with(['guardians' => fn ($q) => $q->wherePivot('is_primary', true)]),
            'schoolClass:id,school_id,name', 'section:id,school_id,name', 'items']);
        $school = $this->tenant->require();

        return [
            'school' => $school->only(['name', 'name_bn', 'address', 'phone', 'email']),
            'invoice' => $this->present->invoice($invoice, now($school->timezone)->toDateString()) + [
                'student_bn' => $invoice->student?->name_bn, 'guardian' => $invoice->student?->guardians->first()?->full_name,
                'items' => $invoice->items->map->only(['kind', 'description', 'amount', 'discount_amount', 'discount_note', 'line_total'])->values(),
            ],
            'note' => $this->settings->get('receipt_note'),
        ];
    }

    /** @return array<string, mixed> */
    public function receipt(FeePayment $payment): array
    {
        $payment->load(['student' => fn ($q) => $q->select(['id', 'school_id', 'full_name', 'name_bn', 'admission_no']), 'receiver:id,school_id,name', 'reversal',
            'allocations.invoice' => fn ($q) => $q->with('items')]);
        $enrollment = $payment->student?->enrollments()->with(['schoolClass:id,school_id,name', 'section:id,school_id,name'])->latest('id')->first();

        return [
            'school' => $this->tenant->require()->only(['name', 'name_bn', 'address', 'phone', 'email']),
            'payment' => $this->present->payment($payment) + [
                'student_bn' => $payment->student?->name_bn, 'class' => $enrollment?->schoolClass?->name, 'section' => $enrollment?->section?->name, 'roll' => $enrollment?->roll_no,
                'notes' => $payment->notes,
                'allocations' => $payment->allocations->map(fn ($a) => [
                    'invoice_no' => $a->invoice?->invoice_no, 'period' => $a->invoice?->billing_period, 'amount' => $a->amount,
                    'items' => $a->invoice?->items->map->only(['description', 'line_total'])->values(),
                    'balance_now' => $a->invoice ? Money::format($a->invoice->balance()) : null,
                ]),
            ],
            'note' => $this->settings->get('receipt_note'),
        ];
    }
}
