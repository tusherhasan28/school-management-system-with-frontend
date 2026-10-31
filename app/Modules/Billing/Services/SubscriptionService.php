<?php

namespace App\Modules\Billing\Services;

use App\Enums\SchoolStatus;
use App\Models\School;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\PlatformInvoice;
use App\Modules\Billing\Models\PlatformPayment;
use App\Modules\Billing\Models\Subscription;
use App\Services\Audit\AuditLogger;
use App\Services\Tenancy\SchoolLifecycle;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Subscription lifecycle and platform billing.
 *
 *   trialing ──(trial ends)──▶ active ──(period ends, renews)──▶ active
 *        │                        │
 *        └──────(invoice due, unpaid)──▶ past_due (grace_ends_at) ──(grace over)──▶ suspended
 *   paid ──▶ active (a school suspended for non-payment is reactivated)
 *   cancel_at_period_end ──(period ends)──▶ cancelled ;  auto_renew off ──(period ends)──▶ expired
 *
 * Suspension, cancellation and expiry never delete data: they change the
 * school's status, which only blocks access (config/tenancy.php).
 */
class SubscriptionService
{
    public function __construct(
        private readonly SchoolLifecycle $lifecycle,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Put a school on a plan. Replaces the current subscription, if any.
     * With a trial, the first invoice comes before the trial ends; without
     * one, the first period is invoiced now.
     */
    public function assign(School $school, Plan $plan, string $cycle, bool $trial = false, bool $autoRenew = true, ?CarbonImmutable $start = null, ?string $notes = null): Subscription
    {
        if (! in_array($cycle, ['monthly', 'yearly'], true)) {
            throw ValidationException::withMessages(['billing_cycle' => __('billing.invalid_cycle')]);
        }

        if ($trial && $plan->trial_days < 1) {
            throw ValidationException::withMessages(['trial' => __('billing.plan_has_no_trial')]);
        }

        $start ??= CarbonImmutable::now();

        return DB::transaction(function () use ($school, $plan, $cycle, $trial, $autoRenew, $start, $notes) {
            $previous = Subscription::query()->where('school_id', $school->id)->live()->lockForUpdate()->get();

            foreach ($previous as $old) {
                $old->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'ended_at' => now(), 'notes' => 'replaced'])->save();
                // Unpaid invoices of the replaced subscription are voided, not deleted.
                $old->invoices()->where('status', 'unpaid')->get()->each(fn (PlatformInvoice $i) => $this->voidInvoice($i, 'replaced by a new subscription'));
            }

            $end = $trial ? $start->addDays($plan->trial_days) : $this->addCycle($start, $cycle);
            $sub = new Subscription([
                'school_id' => $school->id, 'plan_id' => $plan->id, 'status' => $trial ? 'trialing' : 'active', 'billing_cycle' => $cycle,
                'amount' => $plan->price($cycle), 'starts_at' => $start, 'current_period_start' => $start, 'current_period_end' => $end,
                'trial_ends_at' => $trial ? $end : null, 'auto_renew' => $autoRenew, 'notes' => $notes,
            ]);
            $sub->save();

            if (! $trial) {
                $this->issuePeriodInvoice($sub, $start, $end, $start);
            }

            $this->syncSchool($school, $trial ? SchoolStatus::Trial : SchoolStatus::Active, 'billing');
            $this->audit->platform('subscription.assigned', $sub, $school->id, ['plan' => $plan->code, 'cycle' => $cycle, 'trial' => $trial, 'replaced' => $previous->pluck('id')->all()]);

            return $sub;
        });
    }

    /**
     * Daily run over every live subscription. Each step is idempotent, so the
     * job can run more than once a day (or catch up after downtime).
     *
     * @return array<string, int> counts per step
     */
    public function tick(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $counts = ['invoiced' => 0, 'renewed' => 0, 'ended' => 0, 'past_due' => 0, 'suspended' => 0];
        $lead = $now->addDays((int) config('billing.invoice_lead_days', 7));

        // 1. Renewal invoices shortly before the period ends.
        Subscription::query()->whereIn('status', ['trialing', 'active', 'past_due'])->where('auto_renew', true)->where('cancel_at_period_end', false)
            ->where('current_period_end', '<=', $lead)->each(function (Subscription $sub) use (&$counts) {
                $start = CarbonImmutable::parse($sub->current_period_end);

                if ($this->issuePeriodInvoice($sub, $start, $this->addCycle($start, $sub->billing_cycle), $start)) {
                    $counts['invoiced']++;
                }
            });

        // 2. Period ends: renew, cancel or expire.
        Subscription::query()->whereIn('status', ['trialing', 'active', 'past_due'])->where('current_period_end', '<=', $now)
            ->each(function (Subscription $sub) use ($now, &$counts) {
                DB::transaction(function () use ($sub, $now, &$counts) {
                    if ($sub->cancel_at_period_end || ! $sub->auto_renew) {
                        $status = $sub->cancel_at_period_end ? 'cancelled' : 'expired';
                        $sub->forceFill(['status' => $status, 'ended_at' => $now, 'cancelled_at' => $sub->cancelled_at ?? ($status === 'cancelled' ? $now : null)])->save();
                        $this->syncSchool($sub->school, $status === 'cancelled' ? SchoolStatus::Cancelled : SchoolStatus::Suspended, 'subscription '.$status);
                        $this->audit->platform('subscription.'.$status, $sub, $sub->school_id);
                        $counts['ended']++;

                        return;
                    }

                    // Roll forward period by period (catches up after downtime).
                    $start = CarbonImmutable::parse($sub->current_period_start);
                    $end = CarbonImmutable::parse($sub->current_period_end);

                    while ($end->lessThanOrEqualTo($now)) {
                        $start = $end;
                        $end = $this->addCycle($start, $sub->billing_cycle);
                    }

                    $wasTrial = $sub->status === 'trialing';
                    $sub->forceFill(['current_period_start' => $start, 'current_period_end' => $end, 'status' => $wasTrial ? 'active' : $sub->status])->save();

                    if ($wasTrial) {
                        $this->syncSchool($sub->school, SchoolStatus::Active, 'billing');
                    }

                    $counts['renewed']++;
                });
            });

        // 3. Unpaid invoice past its due date: grace period starts.
        Subscription::query()->whereIn('status', ['trialing', 'active'])->whereHas('invoices', fn ($q) => $q->where('status', 'unpaid')->where('due_at', '<=', $now))
            ->with(['plan', 'invoices' => fn ($q) => $q->where('status', 'unpaid')->orderBy('due_at')])
            ->each(function (Subscription $sub) use (&$counts) {
                $due = CarbonImmutable::parse($sub->invoices->first()->due_at);
                $sub->forceFill(['status' => 'past_due', 'grace_ends_at' => $due->addDays($sub->plan->grace_days)])->save();
                $this->audit->platform('subscription.past_due', $sub, $sub->school_id, ['grace_ends_at' => $sub->grace_ends_at?->toIso8601String()]);
                $counts['past_due']++;
            });

        // 4. Grace over and still unpaid: suspend (data is kept).
        Subscription::query()->where('status', 'past_due')->where('grace_ends_at', '<=', $now)
            ->each(function (Subscription $sub) use ($now, &$counts) {
                if (! $sub->invoices()->where('status', 'unpaid')->where('due_at', '<=', $now)->exists()) {
                    return; // paid meanwhile; payment handling restores it
                }

                DB::transaction(function () use ($sub, $now) {
                    $sub->forceFill(['status' => 'suspended', 'suspended_at' => $now])->save();
                    $this->syncSchool($sub->school, SchoolStatus::Suspended, 'subscription unpaid');
                    $this->audit->platform('subscription.suspended', $sub, $sub->school_id, ['reason' => 'unpaid']);
                });
                $counts['suspended']++;
            });

        return $counts;
    }

    /** Record a (manual) payment that settles an invoice in full. */
    public function recordPayment(PlatformInvoice $invoice, array $data): PlatformPayment
    {
        return DB::transaction(function () use ($invoice, $data) {
            $invoice = PlatformInvoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($invoice->status !== 'unpaid') {
                throw ValidationException::withMessages(['invoice' => __('billing.invoice_not_payable')]);
            }

            if (Money::parse((string) $data['amount']) !== Money::parse($invoice->total)) {
                throw ValidationException::withMessages(['amount' => __('billing.amount_must_match', ['total' => $invoice->total])]);
            }

            $payment = PlatformPayment::create([
                'school_id' => $invoice->school_id, 'platform_invoice_id' => $invoice->id, 'method' => $data['method'],
                'gateway' => 'manual', 'transaction_id' => $data['transaction_id'] ?? null, 'amount' => $invoice->total,
                'status' => 'successful', 'received_at' => $data['received_at'] ?? now(), 'recorded_by' => Auth::guard('platform')->id(),
                'notes' => $data['notes'] ?? null,
            ]);
            $invoice->forceFill(['status' => 'paid', 'paid_at' => $payment->received_at])->save();
            $this->audit->platform('billing.payment_recorded', $payment, $invoice->school_id, ['invoice' => $invoice->invoice_number, 'amount' => $payment->amount, 'method' => $payment->method]);

            if ($invoice->subscription_id) {
                $this->settle(Subscription::query()->lockForUpdate()->findOrFail($invoice->subscription_id));
            }

            return $payment;
        });
    }

    /** A manual invoice (setup fee, extra SMS pack, ...). */
    public function issueManualInvoice(School $school, string $description, string $amount, CarbonImmutable $due): PlatformInvoice
    {
        $invoice = $this->newInvoice($school->id, null, $description, $amount, $due, null, null, null);
        $this->audit->platform('billing.invoice_issued', $invoice, $school->id, ['total' => $invoice->total]);

        return $invoice;
    }

    public function voidInvoice(PlatformInvoice $invoice, string $reason): void
    {
        if ($invoice->status !== 'unpaid') {
            throw ValidationException::withMessages(['invoice' => __('billing.invoice_not_voidable')]);
        }

        $invoice->forceFill(['status' => 'void', 'voided_at' => now(), 'void_reason' => $reason])->save();
        $this->audit->platform('billing.invoice_voided', $invoice, $invoice->school_id, ['reason' => $reason]);

        if ($invoice->subscription_id && ($sub = Subscription::query()->find($invoice->subscription_id))?->isLive()) {
            $this->settle($sub);
        }
    }

    public function suspend(Subscription $sub, string $reason): void
    {
        DB::transaction(function () use ($sub, $reason) {
            $sub->forceFill(['status' => 'suspended', 'suspended_at' => now()])->save();
            // The "subscription" prefix lets billing reactivate the school later.
            $this->syncSchool($sub->school, SchoolStatus::Suspended, 'subscription: '.$reason);
            $this->audit->platform('subscription.suspended', $sub, $sub->school_id, ['reason' => $reason]);
        });
    }

    public function reactivate(Subscription $sub): void
    {
        if ($sub->status !== 'suspended') {
            throw ValidationException::withMessages(['subscription' => __('billing.not_suspended')]);
        }

        DB::transaction(function () use ($sub) {
            $this->resume($sub);
            $this->audit->platform('subscription.reactivated', $sub, $sub->school_id);
        });
    }

    /** Cancel now, or at the end of the paid period. */
    public function cancel(Subscription $sub, bool $atPeriodEnd, ?string $reason = null): void
    {
        DB::transaction(function () use ($sub, $atPeriodEnd, $reason) {
            if ($atPeriodEnd) {
                $sub->forceFill(['cancel_at_period_end' => true, 'cancelled_at' => now()])->save();
            } else {
                $sub->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'ended_at' => now()])->save();
                $sub->invoices()->where('status', 'unpaid')->get()->each(fn (PlatformInvoice $i) => $this->voidInvoice($i, 'subscription cancelled'));
                $this->syncSchool($sub->school, SchoolStatus::Cancelled, 'subscription cancelled');
            }

            $this->audit->platform('subscription.cancelled', $sub, $sub->school_id, ['at_period_end' => $atPeriodEnd, 'reason' => $reason]);
        });
    }

    public function extendTrial(Subscription $sub, int $days): void
    {
        if ($sub->status !== 'trialing') {
            throw ValidationException::withMessages(['days' => __('billing.not_trialing')]);
        }

        $end = CarbonImmutable::parse($sub->current_period_end)->addDays($days);
        $sub->forceFill(['current_period_end' => $end, 'trial_ends_at' => $end])->save();
        $this->audit->platform('subscription.trial_extended', $sub, $sub->school_id, ['days' => $days]);
    }

    /** After a payment or void: back to good standing if nothing overdue remains. */
    private function settle(Subscription $sub): void
    {
        $overdue = $sub->invoices()->where('status', 'unpaid')->where('due_at', '<=', now())->exists();

        if ($overdue || ! in_array($sub->status, ['past_due', 'suspended'], true)) {
            return;
        }

        $this->resume($sub);
    }

    private function resume(Subscription $sub): void
    {
        $wasSuspended = $sub->status === 'suspended';
        $fill = ['status' => 'active', 'grace_ends_at' => null, 'suspended_at' => null];

        // Service resumes from today: the time spent suspended is not billed again.
        if ($wasSuspended && CarbonImmutable::parse($sub->current_period_end)->isPast()) {
            $fill['current_period_start'] = now();
            $fill['current_period_end'] = $this->addCycle(CarbonImmutable::now(), $sub->billing_cycle);
        }

        $sub->forceFill($fill)->save();

        if ($wasSuspended) {
            $this->syncSchool($sub->school, SchoolStatus::Active, 'billing');
        }
    }

    /** Invoice for one period; returns null when that period already has one. */
    private function issuePeriodInvoice(Subscription $sub, CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $due): ?PlatformInvoice
    {
        $key = 'sub:'.$sub->id.':'.$start->utc()->format('Y-m-d\TH:i');

        if (PlatformInvoice::query()->where('period_key', $key)->exists()) {
            return null;
        }

        $sub->loadMissing('plan');
        $description = sprintf('%s plan, %s (%s – %s)', $sub->plan->name, $sub->billing_cycle, $start->toDateString(), $end->subDay()->toDateString());
        $invoice = $this->newInvoice($sub->school_id, $sub->id, $description, $sub->amount, $due, $start, $end, $key);
        $this->audit->platform('billing.invoice_issued', $invoice, $sub->school_id, ['total' => $invoice->total, 'period_start' => $start->toDateString()]);

        return $invoice;
    }

    private function newInvoice(int $schoolId, ?int $subscriptionId, string $description, string $amount, CarbonImmutable $due, ?CarbonImmutable $start, ?CarbonImmutable $end, ?string $key): PlatformInvoice
    {
        return DB::transaction(function () use ($schoolId, $subscriptionId, $description, $amount, $due, $start, $end, $key) {
            $year = now()->format('Y');
            $last = PlatformInvoice::query()->where('invoice_number', 'like', "PINV-{$year}-%")->lockForUpdate()->orderByDesc('id')->value('invoice_number');
            $next = $last ? ((int) substr($last, -5)) + 1 : 1;

            $invoice = new PlatformInvoice([
                'school_id' => $schoolId, 'subscription_id' => $subscriptionId, 'invoice_number' => sprintf('PINV-%s-%05d', $year, $next),
                'description' => $description, 'period_start' => $start, 'period_end' => $end,
                'subtotal' => $amount, 'total' => $amount, 'issued_at' => now(), 'due_at' => $due, 'period_key' => $key,
            ]);
            $invoice->save();

            return $invoice;
        });
    }

    private function addCycle(CarbonImmutable $from, string $cycle): CarbonImmutable
    {
        return $cycle === 'yearly' ? $from->addYearNoOverflow() : $from->addMonthNoOverflow();
    }

    /** Move the school to the status the subscription implies, when that is a legal transition. */
    private function syncSchool(School $school, SchoolStatus $to, string $reason): void
    {
        $school->refresh();

        if ($school->status === $to || $school->status === SchoolStatus::Archived) {
            return;
        }

        // A school a platform admin suspended for another reason is not reactivated by billing.
        if ($to === SchoolStatus::Active && $school->status === SchoolStatus::Suspended && ! str_starts_with((string) $school->suspension_reason, 'subscription')) {
            return;
        }

        try {
            $this->lifecycle->transition($school, $to, $to === SchoolStatus::Suspended ? $reason : null);
        } catch (ValidationException) {
            // Not a legal move from the current status (e.g. pending → suspended): leave it to platform staff.
        }
    }
}

