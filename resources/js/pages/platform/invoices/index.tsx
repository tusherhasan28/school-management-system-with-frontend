import { Head, Link, router } from '@inertiajs/react';
import { Receipt, Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { PlatformInvoice } from '@/components/billing/shared';
import { InvoiceBadge } from '@/components/billing/shared';
import type { Column } from '@/components/shared/data-table';
import { DataTable } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { Pagination } from '@/components/shared/pagination';
import { StatCard } from '@/components/shared/stat-card';
import { Input } from '@/components/ui/input';
import { formatDateTime, formatMoney, useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes/platform';
import { index, show } from '@/routes/platform/invoices';
import type { Paginated } from '@/types';

type Props = {
    invoices: Paginated<PlatformInvoice>;
    filters: { status: string; search: string };
    totals: { unpaid: string; overdue: string; paid_this_month: string };
};

export default function InvoicesIndex({ invoices, filters, totals }: Props) {
    const t = useT();
    const [search, setSearch] = useState(filters.search);
    const apply = (next: Partial<Props['filters']>) => router.get(index.url(), Object.fromEntries(Object.entries({ ...filters, ...next }).filter(([, v]) => v !== '')), { preserveState: true, replace: true });

    useEffect(() => {
        if (search === filters.search) {
            return;
        }

        const id = setTimeout(() => apply({ search }), 300);

        return () => clearTimeout(id);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const columns: Column<PlatformInvoice>[] = [
        { key: 'no', header: t('Invoice'), cell: (i) => <Link href={show(i.id)} className="font-medium hover:underline">{i.invoice_number}</Link> },
        { key: 'school', header: t('School'), cell: (i) => i.school },
        { key: 'total', header: t('Total'), cell: (i) => <span className="tabular-nums">{formatMoney(i.total, 'en')}</span> },
        { key: 'status', header: t('Status'), cell: (i) => <InvoiceBadge invoice={i} /> },
        { key: 'due', header: t('Due'), cell: (i) => formatDateTime(i.due_at, 'en'), hideBelow: 'md' },
        { key: 'desc', header: t('Description'), cell: (i) => <span className="text-muted-foreground">{i.description}</span>, hideBelow: 'lg' },
    ];

    return (
        <>
            <Head title={t('Invoices')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader title={t('Invoices')} description={t('Invoices to schools. Payments are recorded by billing staff after the money arrives.')} />
                <div className="grid gap-4 sm:grid-cols-3">
                    <StatCard label={t('Unpaid')} value={formatMoney(totals.unpaid, 'en')} />
                    <StatCard label={t('Overdue')} value={formatMoney(totals.overdue, 'en')} tone="danger" />
                    <StatCard label={t('Paid this month')} value={formatMoney(totals.paid_this_month, 'en')} tone="success" />
                </div>
                <div className="flex flex-col gap-3 sm:flex-row">
                    <div className="relative flex-1 sm:max-w-sm">
                        <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('Invoice number or school')} className="pl-9" aria-label={t('Search')} />
                    </div>
                    <div className="flex flex-wrap gap-1">
                        {['', 'unpaid', 'overdue', 'paid', 'void'].map((s) => (
                            <button key={s || 'all'} type="button" onClick={() => apply({ status: s })} className={cn('rounded-full border px-3 py-1 text-sm', filters.status === s ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted')}>
                                {s ? t(`pinvoice_${s}`) : t('All')}
                            </button>
                        ))}
                    </div>
                </div>
                {invoices.data.length === 0 ? <EmptyState icon={Receipt} title={t('No invoices')} /> : <DataTable columns={columns} rows={invoices.data} rowKey={(i) => i.id} />}
                <Pagination page={invoices} />
            </div>
        </>
    );
}

InvoicesIndex.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Invoices', href: index() }] };
