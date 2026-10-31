import { Head, Link, router, useForm } from '@inertiajs/react';
import { Banknote, Printer, RotateCcw } from 'lucide-react';
import { useState } from 'react';
import PaymentController from '@/actions/App/Modules/Finance/Http/Controllers/PaymentController';
import { DataTable } from '@/components/shared/data-table';
import type { Column } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { Field, selectClass } from '@/components/shared/fields';
import { PageHeader } from '@/components/shared/page-header';
import { Pagination } from '@/components/shared/pagination';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime, formatMoney, useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { dashboard as home } from '@/routes';
import { dashboard } from '@/routes/fees';
import { index, receipt } from '@/routes/fees/payments';
import { show as ledger } from '@/routes/fees/students';
import type { Paginated } from '@/types';
import { PaymentStatus } from './shared';
import type { Payment } from './shared';

type Props = {
    payments: Paginated<Payment>;
    filters: { from: string; to: string; method: string; status: string; q: string };
    can: { refund: boolean };
};

export default function Payments({ payments, filters, can }: Props) {
    const t = useT();
    const go = (patch: Record<string, string | null>) => router.get(index.url(), { ...filters, ...patch }, { preserveState: true, preserveScroll: true, replace: true });

    const columns: Column<Payment>[] = [
        { key: 'receipt', header: t('Receipt'), cell: (p) => <span className="font-mono text-xs">{p.receipt_no ?? '—'}</span> },
        { key: 'student', header: t('Student'), cell: (p) => <Link href={ledger(p.student_id)} className="font-medium hover:text-primary">{p.student}</Link> },
        { key: 'when', header: t('Received'), cell: (p) => formatDateTime(p.received_at), hideBelow: 'md' },
        { key: 'method', header: t('Method'), cell: (p) => <>{t('method_' + p.method)}{p.reference && <span className="block text-xs text-muted-foreground">{p.reference}</span>}</>, hideBelow: 'sm' },
        { key: 'by', header: t('Received by'), cell: (p) => p.received_by ?? p.gateway ?? '—', hideBelow: 'lg' },
        { key: 'amount', header: t('Amount'), cell: (p) => <span className={cn('font-medium', p.reversal && 'line-through')}>{formatMoney(p.amount)}</span>, className: 'text-right tabular-nums' },
        { key: 'status', header: t('Status'), cell: (p) => <PaymentStatus payment={p} /> },
        {
            key: 'actions', header: '', className: 'text-right',
            cell: (p) => p.status === 'successful' && (
                <div className="flex justify-end gap-1">
                    <Button size="icon" variant="ghost" asChild><a href={receipt.url(p.id)} target="_blank" rel="noreferrer" aria-label={t('Print receipt')}><Printer /></a></Button>
                    {can.refund && !p.reversal && <ReverseDialog payment={p} />}
                </div>
            ),
        },
    ];

    return (
        <>
            <Head title={t('Payments')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader title={t('Payments')} description={t('Every payment received, with its receipt. Mistakes are corrected by a reversal, never by editing.')} />
                <div className="flex flex-wrap gap-2">
                    <Input className="w-52" defaultValue={filters.q} placeholder={t('Receipt, reference or student')} onKeyDown={(e) => e.key === 'Enter' && go({ q: (e.target as HTMLInputElement).value, page: null })} aria-label={t('Search')} />
                    <Input type="date" className="w-40" value={filters.from} onChange={(e) => go({ from: e.target.value, page: null })} aria-label={t('From')} />
                    <Input type="date" className="w-40" value={filters.to} onChange={(e) => go({ to: e.target.value, page: null })} aria-label={t('To')} />
                    <select className={cn(selectClass, 'w-40')} value={filters.method} onChange={(e) => go({ method: e.target.value, page: null })} aria-label={t('Method')}>
                        <option value="">{t('All methods')}</option>
                        {['cash', 'mobile_banking', 'bank', 'cheque', 'online'].map((m) => <option key={m} value={m}>{t('method_' + m)}</option>)}
                    </select>
                    <select className={cn(selectClass, 'w-40')} value={filters.status} onChange={(e) => go({ status: e.target.value, page: null })} aria-label={t('Status')}>
                        <option value="">{t('All statuses')}</option>
                        {['successful', 'reversed', 'pending', 'failed', 'cancelled'].map((s) => <option key={s} value={s}>{t('payment_' + s)}</option>)}
                    </select>
                </div>
                {payments.data.length === 0 ? <EmptyState icon={Banknote} title={t('No payments in this period')} /> : <DataTable columns={columns} rows={payments.data} rowKey={(p) => p.id} />}
                <Pagination page={payments} />
            </div>
        </>
    );
}

function ReverseDialog({ payment }: { payment: Payment }) {
    const t = useT();
    const [open, setOpen] = useState(false);
    const form = useForm({ type: 'reversal', reason: '', refund_method: '', refund_reference: '' });

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild><Button size="icon" variant="ghost" aria-label={t('Reverse or refund :receipt', { receipt: payment.receipt_no ?? '' })}><RotateCcw /></Button></DialogTrigger>
            <DialogContent>
                <DialogTitle>{t('Reverse or refund :receipt', { receipt: payment.receipt_no ?? '' })}</DialogTitle>
                <DialogDescription>{t(':amount from :student. The whole payment is cancelled and its invoices become due again. This is recorded and cannot be undone.', { amount: formatMoney(payment.amount), student: payment.student ?? '' })}</DialogDescription>
                <div className="flex gap-1 rounded-lg bg-muted p-1 text-sm">
                    {(['reversal', 'refund'] as const).map((type) => (
                        <button key={type} type="button" className={cn('flex-1 rounded-md px-2 py-1', form.data.type === type && 'bg-background shadow-xs')} onClick={() => form.setData('type', type)}>{t('reverse_' + type)}</button>
                    ))}
                </div>
                <p className="text-xs text-muted-foreground">{form.data.type === 'reversal' ? t('Recorded by mistake, wrong student, or a bounced cheque: the money was never really received.') : t('The money is returned to the family.')}</p>
                <Field label={t('Reason')} name="reason" required error={form.errors.reason}><Input id="reason" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} /></Field>
                {form.data.type === 'refund' && (
                    <div className="grid grid-cols-2 gap-3">
                        <Field label={t('Refunded by')} name="refund_method" required error={form.errors.refund_method}>
                            <select id="refund_method" className={selectClass} value={form.data.refund_method} onChange={(e) => form.setData('refund_method', e.target.value)}>
                                <option value="">—</option>
                                {['cash', 'mobile_banking', 'bank', 'cheque'].map((m) => <option key={m} value={m}>{t('method_' + m)}</option>)}
                            </select>
                        </Field>
                        <Field label={t('Reference')} name="refund_reference" error={form.errors.refund_reference}><Input id="refund_reference" value={form.data.refund_reference} onChange={(e) => form.setData('refund_reference', e.target.value)} /></Field>
                    </div>
                )}
                <Button variant="destructive" disabled={form.processing} onClick={() => form.post(PaymentController.reverse.url(payment.id), { preserveScroll: true, onSuccess: () => setOpen(false) })}>{form.processing && <Spinner />}{t('confirm_' + form.data.type)}</Button>
            </DialogContent>
        </Dialog>
    );
}

Payments.layout = { breadcrumbs: [{ title: 'Dashboard', href: home() }, { title: 'Fees', href: dashboard() }, { title: 'Payments', href: index() }] };
