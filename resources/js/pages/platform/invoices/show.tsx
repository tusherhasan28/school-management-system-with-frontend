import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Printer } from 'lucide-react';
import type { PlatformInvoice } from '@/components/billing/shared';
import { InvoiceBadge } from '@/components/billing/shared';
import InputError from '@/components/input-error';
import { selectClass } from '@/components/shared/fields';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatDay, formatDateTime, formatMoney, useT } from '@/lib/i18n';
import { dashboard } from '@/routes/platform';
import { index, pay, voidMethod as voidInvoice } from '@/routes/platform/invoices';
import { show as showSchool } from '@/routes/platform/schools';

type Props = {
    invoice: PlatformInvoice & { subtotal: string; discount: string; tax: string };
    school: { id: number; name: string; name_bn: string | null; address: string | null; phone: string | null; email: string | null; slug: string };
    payments: { id: number; method: string; transaction_id: string | null; amount: string; received_at: string; recorded_by: string | null; notes: string | null }[];
    instructions: string | null;
    can: { manage: boolean };
};

export default function InvoiceShow({ invoice, school, payments, instructions, can }: Props) {
    const t = useT();
    const payForm = useForm({ amount: invoice.total, method: 'bank', transaction_id: '', received_on: '', notes: '' });
    const voidForm = useForm({ reason: '' });
    const payable = invoice.status === 'unpaid';

    return (
        <>
            <Head title={invoice.invoice_number} />
            <style>{'@media print { .no-print { display: none !important; } [data-sidebar], header { display: none !important; } }'}</style>
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="no-print flex flex-wrap items-center justify-between gap-2">
                    <Button variant="outline" asChild><Link href={index()}><ArrowLeft />{t('All invoices')}</Link></Button>
                    <Button variant="outline" onClick={() => window.print()}><Printer />{t('Print')}</Button>
                </div>

                <article className="mx-auto w-full max-w-3xl rounded-xl border border-border/70 bg-card p-6 sm:p-8">
                    <header className="flex flex-wrap items-start justify-between gap-4 border-b pb-4">
                        <div>
                            <p className="text-xs tracking-wide text-muted-foreground uppercase">{t('Invoice')}</p>
                            <h1 className="text-xl font-semibold">{invoice.invoice_number}</h1>
                            <div className="mt-1"><InvoiceBadge invoice={invoice} /></div>
                        </div>
                        <dl className="text-right text-sm">
                            <dt className="text-muted-foreground">{t('Issued')}</dt><dd>{formatDay(invoice.issued_at)}</dd>
                            <dt className="mt-1 text-muted-foreground">{t('Due')}</dt><dd>{formatDay(invoice.due_at)}</dd>
                        </dl>
                    </header>
                    <section className="mt-4 text-sm">
                        <p className="text-muted-foreground">{t('Billed to')}</p>
                        <p className="font-medium"><Link href={showSchool(school.id)} className="hover:underline">{school.name}</Link></p>
                        {school.address && <p>{school.address}</p>}
                        <p className="text-muted-foreground">{[school.phone, school.email].filter(Boolean).join(' · ')}</p>
                    </section>
                    <table className="mt-6 w-full text-sm">
                        <tbody className="divide-y">
                            <tr><td className="py-2">{invoice.description}</td><td className="py-2 text-right tabular-nums">{formatMoney(invoice.subtotal, 'en')}</td></tr>
                            {Number(invoice.discount) > 0 && <tr><td className="py-2">{t('Discount')}</td><td className="py-2 text-right tabular-nums">−{formatMoney(invoice.discount, 'en')}</td></tr>}
                            {Number(invoice.tax) > 0 && <tr><td className="py-2">{t('Tax')}</td><td className="py-2 text-right tabular-nums">{formatMoney(invoice.tax, 'en')}</td></tr>}
                            <tr className="font-semibold"><td className="py-2">{t('Total')}</td><td className="py-2 text-right tabular-nums">{formatMoney(invoice.total, 'en')}</td></tr>
                        </tbody>
                    </table>
                    {invoice.status === 'void' && <p className="mt-4 text-sm text-muted-foreground">{t('Voided')}: {invoice.void_reason}</p>}
                    {payable && instructions && <p className="mt-6 rounded-lg bg-muted/60 p-3 text-sm whitespace-pre-line">{instructions}</p>}
                    {payments.length > 0 && (
                        <section className="mt-6">
                            <h2 className="mb-2 text-sm font-medium">{t('Payments')}</h2>
                            <ul className="divide-y text-sm">
                                {payments.map((p) => (
                                    <li key={p.id} className="flex flex-wrap justify-between gap-2 py-2">
                                        <span>{formatDateTime(p.received_at, 'en')} · {t(`method_${p.method}`)}{p.transaction_id ? ` · ${p.transaction_id}` : ''}{p.recorded_by ? ` · ${p.recorded_by}` : ''}</span>
                                        <span className="tabular-nums">{formatMoney(p.amount, 'en')}</span>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}
                </article>

                {can.manage && payable && (
                    <div className="no-print mx-auto grid w-full max-w-3xl gap-4 md:grid-cols-2">
                        <form className="grid gap-3 rounded-xl border border-border/70 bg-card p-5" onSubmit={(e) => { e.preventDefault(); payForm.post(pay.url(invoice.id), { preserveScroll: true }); }}>
                            <h2 className="font-medium">{t('Record payment')}</h2>
                            <p className="text-sm text-muted-foreground">{t('Record only money that has arrived. The full amount settles the invoice and restores a suspended school.')}</p>
                            <div className="grid gap-1.5"><Label htmlFor="amount">{t('Amount')}</Label><Input id="amount" inputMode="decimal" value={payForm.data.amount} onChange={(e) => payForm.setData('amount', e.target.value)} /><InputError message={payForm.errors.amount} /></div>
                            <div className="grid gap-1.5"><Label htmlFor="method">{t('Method')}</Label>
                                <select id="method" className={selectClass} value={payForm.data.method} onChange={(e) => payForm.setData('method', e.target.value)}>
                                    {['bank', 'mobile_banking', 'cheque', 'cash'].map((m) => <option key={m} value={m}>{t(`method_${m}`)}</option>)}
                                </select>
                            </div>
                            <div className="grid gap-1.5"><Label htmlFor="tx">{t('Transaction / reference')}</Label><Input id="tx" value={payForm.data.transaction_id} onChange={(e) => payForm.setData('transaction_id', e.target.value)} /><InputError message={payForm.errors.transaction_id} /></div>
                            <div className="grid gap-1.5"><Label htmlFor="on">{t('Received on')}</Label><Input id="on" type="date" value={payForm.data.received_on} onChange={(e) => payForm.setData('received_on', e.target.value)} /><InputError message={payForm.errors.received_on} /></div>
                            <InputError message={(payForm.errors as Record<string, string>).invoice} />
                            <Button type="submit" disabled={payForm.processing}>{payForm.processing && <Spinner />}{t('Record payment')}</Button>
                        </form>
                        <form className="grid content-start gap-3 rounded-xl border border-border/70 bg-card p-5" onSubmit={(e) => { e.preventDefault(); voidForm.post(voidInvoice.url(invoice.id), { preserveScroll: true }); }}>
                            <h2 className="font-medium">{t('Void invoice')}</h2>
                            <p className="text-sm text-muted-foreground">{t('For an invoice issued by mistake. It stays on record.')}</p>
                            <div className="grid gap-1.5"><Label htmlFor="reason">{t('Reason')}</Label><Input id="reason" value={voidForm.data.reason} onChange={(e) => voidForm.setData('reason', e.target.value)} /><InputError message={voidForm.errors.reason} /></div>
                            <Button type="submit" variant="destructive" disabled={voidForm.processing}>{t('Void invoice')}</Button>
                        </form>
                    </div>
                )}
            </div>
        </>
    );
}

InvoiceShow.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Invoices', href: index() }] };
