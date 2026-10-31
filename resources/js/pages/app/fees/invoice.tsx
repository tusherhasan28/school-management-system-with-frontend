import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Ban, Banknote, MinusCircle, PlusCircle, Printer } from 'lucide-react';
import { useState } from 'react';
import InvoiceController from '@/actions/App/Modules/Finance/Http/Controllers/InvoiceController';
import InputError from '@/components/input-error';
import { Field } from '@/components/shared/fields';
import { PageHeader } from '@/components/shared/page-header';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { formatDate, formatDateTime, formatMoney, useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { dashboard as home } from '@/routes';
import { dashboard } from '@/routes/fees';
import { index, print } from '@/routes/fees/invoices';
import { receipt } from '@/routes/fees/payments';
import { show as ledger } from '@/routes/fees/students';
import { InvoiceStatus, PaymentStatus, periodLabel } from './shared';
import type { Invoice, Payment } from './shared';

type Item = { id: number; kind: string; description: string; category: string | null; amount: string; discount_amount: string; discount_note: string | null; line_total: string };

type Props = {
    invoice: Invoice & { notes: string | null; cancel_reason: string | null; cancelled_at: string | null; items: Item[]; payments: (Payment & { allocated: string })[] };
    can: { manage: boolean; collect: boolean };
};

export default function InvoiceShow({ invoice: inv, can }: Props) {
    const t = useT();
    const { locale, errors } = usePage().props as { locale: string; errors: Record<string, string> };

    return (
        <>
            <Head title={inv.invoice_no} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={inv.invoice_no}
                    description={`${inv.student} · ${[inv.class, inv.section].filter(Boolean).join(' · ')} · ${periodLabel(inv.billing_period, locale)}`}
                    actions={
                        <>
                            <InvoiceStatus invoice={inv} />
                            <Button variant="outline" asChild><a href={print.url(inv.id)} target="_blank" rel="noreferrer"><Printer />{t('Print')}</a></Button>
                            {can.collect && <Button asChild><Link href={ledger(inv.student_id)}><Banknote />{t('Collect payment')}</Link></Button>}
                        </>
                    }
                />

                {inv.status === 'cancelled' && (
                    <p className="rounded-xl border border-border/70 bg-muted/50 p-3 text-sm">{t('Cancelled :at: :reason', { at: formatDateTime(inv.cancelled_at), reason: inv.cancel_reason ?? '' })}</p>
                )}
                <InputError message={errors.invoice} />

                <section className="overflow-hidden rounded-xl border border-border/70 bg-card">
                    <div className="grid gap-1 border-b border-border/70 px-4 py-3 text-sm sm:grid-cols-3">
                        <p><span className="text-muted-foreground">{t('Issued')}:</span> {formatDate(inv.issue_date)}</p>
                        <p><span className="text-muted-foreground">{t('Due')}:</span> <span className={cn(inv.overdue && 'font-medium text-destructive')}>{formatDate(inv.due_date)}</span></p>
                        <p><Link href={ledger(inv.student_id)} className="text-primary hover:underline">{t('Student fee account')}</Link></p>
                    </div>
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left text-xs text-muted-foreground uppercase">
                            <tr><th className="px-4 py-2">{t('Description')}</th><th className="px-4 py-2 text-right">{t('Amount')}</th><th className="px-4 py-2 text-right">{t('Discount')}</th><th className="px-4 py-2 text-right">{t('Total')}</th></tr>
                        </thead>
                        <tbody className="divide-y divide-border/70">
                            {inv.items.map((i) => (
                                <tr key={i.id} className={cn(i.kind === 'waiver' && 'text-emerald-700 dark:text-emerald-400', i.kind === 'late_fee' && 'text-amber-700 dark:text-amber-400')}>
                                    <td className="px-4 py-2">{i.description}{i.kind !== 'fee' && <span className="ml-2 text-xs text-muted-foreground">({t('line_' + i.kind)})</span>}</td>
                                    <td className="px-4 py-2 text-right tabular-nums">{formatMoney(i.amount)}</td>
                                    <td className="px-4 py-2 text-right tabular-nums">{Number(i.discount_amount) > 0 ? <span title={i.discount_note ?? ''}>−{formatMoney(i.discount_amount)}</span> : '—'}</td>
                                    <td className="px-4 py-2 text-right font-medium tabular-nums">{formatMoney(i.line_total)}</td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot className="border-t-2 border-border text-sm">
                            <tr><td colSpan={3} className="px-4 py-1.5 text-right text-muted-foreground">{t('Total')}</td><td className="px-4 py-1.5 text-right font-medium tabular-nums">{formatMoney(inv.total)}</td></tr>
                            <tr><td colSpan={3} className="px-4 py-1.5 text-right text-muted-foreground">{t('Paid')}</td><td className="px-4 py-1.5 text-right tabular-nums">{formatMoney(inv.paid_total)}</td></tr>
                            <tr><td colSpan={3} className="px-4 py-2 text-right font-medium">{t('Balance')}</td><td className="px-4 py-2 text-right text-base font-semibold tabular-nums">{formatMoney(inv.balance)}</td></tr>
                        </tfoot>
                    </table>
                </section>

                {inv.payments.length > 0 && (
                    <section className="overflow-hidden rounded-xl border border-border/70 bg-card">
                        <h2 className="border-b border-border/70 px-4 py-3 font-medium">{t('Payments')}</h2>
                        <ul className="divide-y divide-border/70 text-sm">
                            {inv.payments.map((p) => (
                                <li key={p.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5">
                                    <div>
                                        <a href={receipt.url(p.id)} target="_blank" rel="noreferrer" className="font-mono text-xs text-primary hover:underline">{p.receipt_no}</a>
                                        <p className="text-xs text-muted-foreground">{formatDateTime(p.received_at)} · {t('method_' + p.method)}{p.reference ? ` · ${p.reference}` : ''} · {p.received_by ?? p.gateway}</p>
                                    </div>
                                    <div className="flex items-center gap-3"><PaymentStatus payment={p} /><span className="font-medium tabular-nums">{formatMoney(p.allocated)}</span></div>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {can.manage && (
                    <div className="flex flex-wrap gap-2">
                        <LineDialog invoiceId={inv.id} kind="waiver" />
                        <LineDialog invoiceId={inv.id} kind="adjustment" />
                        {Number(inv.paid_total) === 0 && <CancelDialog invoiceId={inv.id} />}
                    </div>
                )}
            </div>
        </>
    );
}

function LineDialog({ invoiceId, kind }: { invoiceId: number; kind: 'waiver' | 'adjustment' }) {
    const t = useT();
    const [open, setOpen] = useState(false);
    const form = useForm({ kind, description: '', amount: '' });

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild><Button variant="outline">{kind === 'waiver' ? <MinusCircle /> : <PlusCircle />}{t(kind === 'waiver' ? 'Waive an amount' : 'Add a charge')}</Button></DialogTrigger>
            <DialogContent>
                <DialogTitle>{t(kind === 'waiver' ? 'Waive an amount' : 'Add a charge')}</DialogTitle>
                <DialogDescription>{t('Added as a new line with your name; existing lines never change.')}</DialogDescription>
                <Field label={t('Reason')} name="description" required error={form.errors.description}><Input id="description" value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} /></Field>
                <Field label={t('Amount (BDT)')} name="amount" required error={form.errors.amount}><Input id="amount" inputMode="decimal" value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} /></Field>
                <Button disabled={form.processing} onClick={() => form.post(InvoiceController.addLine.url(invoiceId), { preserveScroll: true, onSuccess: () => { setOpen(false); form.reset(); } })}>{form.processing && <Spinner />}{t('Save')}</Button>
            </DialogContent>
        </Dialog>
    );
}

function CancelDialog({ invoiceId }: { invoiceId: number }) {
    const t = useT();
    const [open, setOpen] = useState(false);
    const form = useForm({ reason: '' });

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild><Button variant="ghost" className="text-destructive"><Ban />{t('Cancel invoice')}</Button></DialogTrigger>
            <DialogContent>
                <DialogTitle>{t('Cancel invoice')}</DialogTitle>
                <DialogDescription>{t('The invoice stays on record as cancelled. A generated invoice can then be generated again for the same month.')}</DialogDescription>
                <Field label={t('Reason')} name="reason" required error={form.errors.reason}><Input id="reason" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} /></Field>
                <Button variant="destructive" disabled={form.processing} onClick={() => form.post(InvoiceController.cancel.url(invoiceId), { preserveScroll: true, onSuccess: () => setOpen(false) })}>{form.processing && <Spinner />}{t('Cancel invoice')}</Button>
            </DialogContent>
        </Dialog>
    );
}

InvoiceShow.layout = { breadcrumbs: [{ title: 'Dashboard', href: home() }, { title: 'Fees', href: dashboard() }, { title: 'Invoices', href: index() }] };
