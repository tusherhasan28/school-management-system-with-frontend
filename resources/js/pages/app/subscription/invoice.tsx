import { Head } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import type { PlatformInvoice } from '@/components/billing/shared';
import { InvoiceBadge } from '@/components/billing/shared';
import { Button } from '@/components/ui/button';
import { formatDate, formatDateTime, formatMoney, useT } from '@/lib/i18n';

type Props = {
    invoice: PlatformInvoice & { subtotal: string; discount: string; tax: string };
    payments: { id: number; method: string; transaction_id: string | null; amount: string; received_at: string }[];
    school: { name: string; name_bn: string | null; address: string | null; phone: string | null; email: string | null };
    platform: string;
    instructions: string | null;
};

/** Printable platform invoice for the school (A4). */
export default function SubscriptionInvoice({ invoice, payments, school, platform, instructions }: Props) {
    const t = useT();

    return (
        <>
            <Head title={invoice.invoice_number} />
            <style>{'@page { size: A4; margin: 14mm; } @media print { .no-print { display: none !important; } .sheet { box-shadow: none !important; border: none !important; margin: 0 !important; } body { background: white !important; } }'}</style>
            <div className="min-h-svh bg-muted/40 py-6 print:bg-white print:py-0">
                <div className="no-print mx-auto mb-4 flex max-w-3xl justify-end px-4"><Button onClick={() => window.print()}><Printer />{t('Print')}</Button></div>
                <article className="sheet mx-auto max-w-3xl rounded-xl border bg-white p-8 text-zinc-900 shadow-sm">
                    <header className="flex flex-wrap items-start justify-between gap-4 border-b pb-4">
                        <div><p className="text-lg font-semibold">{platform}</p><p className="text-sm text-zinc-500">{t('Invoice')} {invoice.invoice_number}</p></div>
                        <div className="text-right text-sm"><InvoiceBadge invoice={invoice} /><p className="mt-1">{t('Issued')} {formatDate(invoice.issued_at)}</p><p>{t('Due')} {formatDate(invoice.due_at)}</p></div>
                    </header>
                    <section className="mt-4 text-sm">
                        <p className="text-zinc-500">{t('Billed to')}</p>
                        <p className="font-medium">{school.name}{school.name_bn ? ` (${school.name_bn})` : ''}</p>
                        {school.address && <p>{school.address}</p>}
                        <p className="text-zinc-500">{[school.phone, school.email].filter(Boolean).join(' · ')}</p>
                    </section>
                    <table className="mt-6 w-full text-sm">
                        <tbody className="divide-y">
                            <tr><td className="py-2">{invoice.description}</td><td className="py-2 text-right tabular-nums">{formatMoney(invoice.subtotal)}</td></tr>
                            {Number(invoice.discount) > 0 && <tr><td className="py-2">{t('Discount')}</td><td className="py-2 text-right">−{formatMoney(invoice.discount)}</td></tr>}
                            {Number(invoice.tax) > 0 && <tr><td className="py-2">{t('Tax')}</td><td className="py-2 text-right">{formatMoney(invoice.tax)}</td></tr>}
                            <tr className="font-semibold"><td className="py-2">{t('Total')}</td><td className="py-2 text-right tabular-nums">{formatMoney(invoice.total)}</td></tr>
                        </tbody>
                    </table>
                    {payments.map((p) => <p key={p.id} className="mt-2 text-sm text-emerald-700">{t('Paid')} {formatMoney(p.amount)} · {formatDateTime(p.received_at)} · {t(`method_${p.method}`)}{p.transaction_id ? ` · ${p.transaction_id}` : ''}</p>)}
                    {instructions && <p className="mt-6 rounded-lg bg-zinc-100 p-3 text-sm whitespace-pre-line">{instructions}</p>}
                </article>
            </div>
        </>
    );
}
