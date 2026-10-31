import { Form, Head, router, usePage } from '@inertiajs/react';
import { FilePlus2, FileText } from 'lucide-react';
import { useState } from 'react';
import InvoiceController from '@/actions/App/Modules/Finance/Http/Controllers/InvoiceController';
import { DataTable } from '@/components/shared/data-table';
import type { Column } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { Field, SelectField, selectClass, TextField } from '@/components/shared/fields';
import { PageHeader } from '@/components/shared/page-header';
import { Pagination } from '@/components/shared/pagination';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { formatDate, formatMoney, useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { dashboard as home } from '@/routes';
import { dashboard } from '@/routes/fees';
import { index, show } from '@/routes/fees/invoices';
import type { Paginated } from '@/types';
import { InvoiceStatus, periodLabel } from './shared';
import type { Invoice } from './shared';

type Props = {
    invoices: Paginated<Invoice>;
    filters: { status: string; class: number | null; section: number | null; period: string; q: string };
    classes: { id: number; name: string; sections: { id: number; name: string }[] }[];
    defaults: { period: string; due_date: string; next_period: string };
    can: { manage: boolean };
};

const statuses = ['open', 'overdue', 'unpaid', 'partially_paid', 'paid', 'cancelled'];

export default function Invoices({ invoices, filters, classes, defaults, can }: Props) {
    const t = useT();
    const { locale } = usePage().props;
    const go = (patch: Record<string, string | number | null>) => router.get(index.url(), { ...filters, ...patch }, { preserveState: true, preserveScroll: true, replace: true });

    const columns: Column<Invoice>[] = [
        { key: 'no', header: t('Invoice'), cell: (r) => <span className="font-mono text-xs">{r.invoice_no}</span> },
        { key: 'student', header: t('Student'), cell: (r) => <><p className="font-medium">{r.student}</p><p className="text-xs text-muted-foreground">{[r.class, r.section].filter(Boolean).join(' · ')}</p></> },
        { key: 'period', header: t('Month'), cell: (r) => periodLabel(r.billing_period, locale), hideBelow: 'md' },
        { key: 'due', header: t('Due'), cell: (r) => formatDate(r.due_date), hideBelow: 'sm' },
        { key: 'total', header: t('Total'), cell: (r) => formatMoney(r.total), className: 'text-right tabular-nums', hideBelow: 'md' },
        { key: 'balance', header: t('Balance'), cell: (r) => <span className="font-medium">{formatMoney(r.balance)}</span>, className: 'text-right tabular-nums' },
        { key: 'status', header: t('Status'), cell: (r) => <InvoiceStatus invoice={r} /> },
    ];
    const current = classes.find((c) => c.id === filters.class);

    return (
        <>
            <Head title={t('Invoices')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader title={t('Invoices')} description={t('Generate monthly invoices, then follow up what is still owed.')} actions={can.manage && <GenerateDialog classes={classes} defaults={defaults} />} />

                <div className="flex flex-wrap gap-2">
                    <Input className="w-56" defaultValue={filters.q} placeholder={t('Search name, admission or invoice no.')} onKeyDown={(e) => e.key === 'Enter' && go({ q: (e.target as HTMLInputElement).value, page: null })} aria-label={t('Search')} />
                    <select className={cn(selectClass, 'w-40')} value={filters.status} onChange={(e) => go({ status: e.target.value, page: null })} aria-label={t('Status')}>
                        <option value="">{t('All statuses')}</option>
                        {statuses.map((s) => <option key={s} value={s}>{t('invoice_' + s)}</option>)}
                    </select>
                    <select className={cn(selectClass, 'w-36')} value={filters.class ?? ''} onChange={(e) => go({ class: e.target.value || null, section: null, page: null })} aria-label={t('Class')}>
                        <option value="">{t('All classes')}</option>
                        {classes.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                    </select>
                    {current && (
                        <select className={cn(selectClass, 'w-32')} value={filters.section ?? ''} onChange={(e) => go({ section: e.target.value || null, page: null })} aria-label={t('Section')}>
                            <option value="">{t('All sections')}</option>
                            {current.sections.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                        </select>
                    )}
                    <Input type="month" className="w-44" value={filters.period} onChange={(e) => go({ period: e.target.value, page: null })} aria-label={t('Month')} />
                </div>

                {invoices.data.length === 0 ? <EmptyState icon={FileText} title={t('No invoices found')} /> : <DataTable columns={columns} rows={invoices.data} rowKey={(r) => r.id} onRowClick={(r) => router.visit(show.url(r.id))} />}
                <Pagination page={invoices} />
            </div>
        </>
    );
}

function GenerateDialog({ classes, defaults }: Pick<Props, 'classes' | 'defaults'>) {
    const t = useT();
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild><Button><FilePlus2 />{t('Generate invoices')}</Button></DialogTrigger>
            <DialogContent>
                <DialogTitle>{t('Generate invoices')}</DialogTitle>
                <DialogDescription>{t('Creates one invoice per enrolled student from the fee plans billed that month, with each student\'s discounts. Students who already have an invoice for the month are skipped, so it is safe to run again.')}</DialogDescription>
                <Form {...InvoiceController.generate.form()} options={{ preserveScroll: true }} onSuccess={() => setOpen(false)} className="grid gap-3">
                    {({ processing, errors }) => (
                        <>
                            <Field label={t('Month')} name="period" required error={errors.period}><Input id="period" name="period" type="month" defaultValue={defaults.period} required /></Field>
                            <SelectField label={t('Class')} name="class_id" placeholder={t('All classes')} options={classes.map((c) => ({ value: c.id, label: c.name }))} error={errors.class_id} />
                            <TextField label={t('Due date')} name="due_date" type="date" defaultValue={defaults.due_date} required error={errors.due_date} />
                            <Button type="submit" disabled={processing}>{processing && <Spinner />}{t('Generate')}</Button>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

Invoices.layout = { breadcrumbs: [{ title: 'Dashboard', href: home() }, { title: 'Fees', href: dashboard() }, { title: 'Invoices', href: index() }] };
