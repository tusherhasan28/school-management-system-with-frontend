<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\FeeInvoice;
use App\Modules\Finance\Models\FeePayment;
use App\Support\Money\Money;

/** Array shapes for invoices and payments shared by the finance screens. */
class FinancePresenter
{
    /** @return array<string, mixed> */
    public function invoice(FeeInvoice $i, ?string $today = null): array
    {
        return [
            'id' => $i->id, 'invoice_no' => $i->invoice_no, 'student_id' => $i->student_id,
            'student' => $i->relationLoaded('student') ? $i->student?->full_name : null,
            'admission_no' => $i->relationLoaded('student') ? $i->student?->admission_no : null,
            'class' => $i->relationLoaded('schoolClass') ? $i->schoolClass?->name : null,
            'section' => $i->relationLoaded('section') ? $i->section?->name : null,
            'billing_period' => $i->billing_period, 'issue_date' => $i->issue_date->toDateString(), 'due_date' => $i->due_date->toDateString(),
            'total' => $i->total, 'paid_total' => $i->paid_total, 'balance' => Money::format($i->balance()),
            'status' => $i->status, 'overdue' => $i->isOverdue($today),
        ];
    }

    /** @return array<string, mixed> */
    public function payment(FeePayment $p): array
    {
        $reversal = $p->relationLoaded('reversal') ? $p->reversal : null;

        return [
            'id' => $p->id, 'receipt_no' => $p->receipt_no, 'student_id' => $p->student_id,
            'student' => $p->relationLoaded('student') ? $p->student?->full_name : null,
            'admission_no' => $p->relationLoaded('student') ? $p->student?->admission_no : null,
            'method' => $p->method, 'gateway' => $p->gateway, 'reference' => $p->reference, 'amount' => $p->amount, 'status' => $p->status,
            'received_at' => $p->received_at?->toIso8601String(), 'received_by' => $p->relationLoaded('receiver') ? $p->receiver?->name : null,
            'reversal' => $reversal ? ['type' => $reversal->type, 'reason' => $reversal->reason, 'at' => $reversal->created_at?->toIso8601String()] : null,
        ];
    }
}
