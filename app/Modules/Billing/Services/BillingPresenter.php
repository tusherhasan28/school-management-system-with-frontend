<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\PlatformInvoice;
use App\Modules\Billing\Models\PlatformPayment;
use App\Modules\Billing\Models\Subscription;

/** Array shapes for plans, subscriptions and invoices (platform and school screens). */
class BillingPresenter
{
    /** @return array<string, mixed> */
    public function plan(Plan $p): array
    {
        return $p->only(['id', 'code', 'name', 'name_bn', 'description', 'price_monthly', 'price_yearly', 'trial_days', 'grace_days', 'is_active', 'is_public', 'sort_order'])
            + ['features' => $p->relationLoaded('features') ? $p->featureValues() : null];
    }

    /** @return array<string, mixed>|null */
    public function subscription(?Subscription $s): ?array
    {
        if ($s === null) {
            return null;
        }

        return [
            'id' => $s->id, 'status' => $s->status, 'billing_cycle' => $s->billing_cycle, 'amount' => $s->amount,
            'plan' => $s->relationLoaded('plan') ? ['id' => $s->plan->id, 'code' => $s->plan->code, 'name' => $s->plan->name, 'name_bn' => $s->plan->name_bn] : null,
            'starts_at' => $s->starts_at->toIso8601String(), 'current_period_start' => $s->current_period_start->toIso8601String(),
            'current_period_end' => $s->current_period_end->toIso8601String(), 'trial_ends_at' => $s->trial_ends_at?->toIso8601String(),
            'grace_ends_at' => $s->grace_ends_at?->toIso8601String(), 'auto_renew' => $s->auto_renew, 'cancel_at_period_end' => $s->cancel_at_period_end,
            'suspended_at' => $s->suspended_at?->toIso8601String(), 'ended_at' => $s->ended_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function invoice(PlatformInvoice $i): array
    {
        return [
            'id' => $i->id, 'invoice_number' => $i->invoice_number, 'description' => $i->description, 'school_id' => $i->school_id,
            'school' => $i->relationLoaded('school') ? $i->school?->name : null,
            'total' => $i->total, 'currency' => $i->currency, 'status' => $i->status, 'overdue' => $i->isOverdue(),
            'issued_at' => $i->issued_at->toIso8601String(), 'due_at' => $i->due_at->toIso8601String(), 'paid_at' => $i->paid_at?->toIso8601String(),
            'period_start' => $i->period_start?->toIso8601String(), 'period_end' => $i->period_end?->toIso8601String(), 'void_reason' => $i->void_reason,
        ];
    }

    /** @return array<string, mixed> */
    public function payment(PlatformPayment $p): array
    {
        return [
            'id' => $p->id, 'method' => $p->method, 'transaction_id' => $p->transaction_id, 'amount' => $p->amount,
            'received_at' => $p->received_at->toIso8601String(), 'recorded_by' => $p->relationLoaded('recorder') ? $p->recorder?->name : null, 'notes' => $p->notes,
        ];
    }
}
