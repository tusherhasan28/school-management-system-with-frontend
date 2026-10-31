<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\FeeInvoice;
use App\Modules\Finance\Models\FeeInvoiceItem;
use App\Modules\Finance\Models\FeePaymentAllocation;
use App\Support\Money\Money;

/**
 * The one place invoice totals are computed. Always called inside the
 * transaction that changed items or allocations, with the invoice row locked.
 */
class InvoiceLedger
{
    /** Sum of items, in paisa. */
    public function total(FeeInvoice $invoice): int
    {
        return Money::sum(FeeInvoiceItem::query()->where('fee_invoice_id', $invoice->id)->pluck('line_total'));
    }

    /** Allocations of successful payments that were not reversed, in paisa. */
    public function paid(FeeInvoice $invoice): int
    {
        return Money::sum(
            FeePaymentAllocation::query()
                ->where('fee_invoice_id', $invoice->id)
                ->whereHas('payment', fn ($q) => $q->where('status', 'successful')->whereDoesntHave('reversal'))
                ->pluck('amount')
        );
    }

    public function refresh(FeeInvoice $invoice): FeeInvoice
    {
        $total = $this->total($invoice);
        $paid = $this->paid($invoice);

        $status = match (true) {
            $invoice->status === 'cancelled' => 'cancelled',
            $paid >= $total => 'paid',
            $paid > 0 => 'partially_paid',
            default => 'unpaid',
        };

        $invoice->forceFill(['total' => Money::format($total), 'paid_total' => Money::format($paid), 'status' => $status])->save();

        return $invoice;
    }

    /** Live balance from the source rows (not the cache), in paisa. */
    public function balance(FeeInvoice $invoice): int
    {
        return $invoice->status === 'cancelled' ? 0 : $this->total($invoice) - $this->paid($invoice);
    }
}
