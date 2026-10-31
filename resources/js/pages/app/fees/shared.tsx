import { StatusBadge } from '@/components/shared/status-badge';
import { useT } from '@/lib/i18n';

export type Invoice = {
    id: number; invoice_no: string; student_id: number; student: string | null; admission_no: string | null; class: string | null; section: string | null;
    billing_period: string | null; issue_date: string; due_date: string; total: string; paid_total: string; balance: string; status: string; overdue: boolean;
};

export type Payment = {
    id: number; receipt_no: string | null; student_id: number; student: string | null; admission_no: string | null; method: string; gateway: string | null;
    reference: string | null; amount: string; status: string; received_at: string | null; received_by: string | null;
    reversal: { type: string; reason: string; at: string | null } | null;
};

const invoiceTone: Record<string, string> = { unpaid: 'pending', partially_paid: 'trial', paid: 'active', cancelled: 'archived', overdue: 'suspended' };

export function InvoiceStatus({ invoice }: { invoice: Pick<Invoice, 'status' | 'overdue'> }) {
    const t = useT();
    const key = invoice.overdue ? 'overdue' : invoice.status;

    return <StatusBadge status={invoiceTone[key]} label={t('invoice_' + key)} />;
}

export function PaymentStatus({ payment }: { payment: Pick<Payment, 'status' | 'reversal'> }) {
    const t = useT();

    if (payment.reversal) {
        return <StatusBadge status="cancelled" label={t('payment_' + payment.reversal.type)} />;
    }

    const tone: Record<string, string> = { successful: 'active', pending: 'pending', failed: 'suspended', cancelled: 'archived' };

    return <StatusBadge status={tone[payment.status]} label={t('payment_' + payment.status)} />;
}

/** "2026-06" → localized month name. */
export function periodLabel(period: string | null, locale: string) {
    if (!period) {
        return '—';
    }

    return new Intl.DateTimeFormat(locale === 'bn' ? 'bn-BD' : 'en-GB', { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(`${period}-01T00:00:00Z`));
}
