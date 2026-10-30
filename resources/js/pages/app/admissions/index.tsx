import { Head, Link, router } from '@inertiajs/react';
import { ClipboardList, ExternalLink, Search } from 'lucide-react';
import type { Column } from '@/components/shared/data-table';
import { DataTable } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { selectClass } from '@/components/shared/fields';
import { PageHeader } from '@/components/shared/page-header';
import { Pagination } from '@/components/shared/pagination';
import { StatusBadge } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useFilters } from '@/hooks/use-filters';
import { formatDateTime, useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index, show } from '@/routes/admissions';
import { admission } from '@/routes/public';
import type { AcademicClass, Paginated } from '@/types';

type Row = { id: number; application_no: string; applicant_name: string; class: string | null; guardian_phone: string; status: string; submitted_at: string };

export const admissionTone: Record<string, string> = { submitted: 'pending', under_review: 'trial', approved: 'active', rejected: 'suspended', enrolled: 'archived' };

type Props = { applications: Paginated<Row>; filters: { search: string; status: string; class: number | null }; counts: Record<string, number>; classes: AcademicClass[] };

export default function AdmissionsIndex({ applications, filters: initial, counts, classes }: Props) {
    const t = useT();
    const { filters, set } = useFilters(index.url(), { ...initial, class: initial.class ?? '' });
    const statuses = ['submitted', 'under_review', 'approved', 'rejected', 'enrolled'];

    const columns: Column<Row>[] = [
        { key: 'no', header: t('Application no.'), cell: (r) => <span className="font-mono text-xs">{r.application_no}</span>, hideBelow: 'sm' },
        { key: 'name', header: t('Applicant'), cell: (r) => <Link href={show(r.id)} className="font-medium hover:underline" onClick={(e) => e.stopPropagation()}>{r.applicant_name}</Link> },
        { key: 'class', header: t('Class'), cell: (r) => r.class ?? '—' },
        { key: 'phone', header: t('Guardian mobile'), cell: (r) => r.guardian_phone, hideBelow: 'md' },
        { key: 'date', header: t('Submitted'), cell: (r) => formatDateTime(r.submitted_at), hideBelow: 'lg' },
        { key: 'status', header: t('Status'), cell: (r) => <StatusBadge status={admissionTone[r.status]} label={t(r.status)} /> },
    ];

    return (
        <>
            <Head title={t('Admissions')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('Admissions')}
                    description={t('Applications from your website. Review, approve and convert them into students.')}
                    actions={<Button variant="outline" asChild><a href={admission.url()} target="_blank" rel="noreferrer"><ExternalLink />{t('Public form')}</a></Button>}
                />
                <div className="flex flex-wrap gap-2">
                    <button onClick={() => set('status', '')} className={cn('rounded-full border px-3 py-1 text-sm', !filters.status ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted')}>
                        {t('All')}
                    </button>
                    {statuses.map((s) => (
                        <button key={s} onClick={() => set('status', s)} className={cn('rounded-full border px-3 py-1 text-sm', filters.status === s ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted')}>
                            {t(s)} <span className="tabular-nums opacity-70">{counts[s] ?? 0}</span>
                        </button>
                    ))}
                </div>
                <div className="grid gap-3 sm:grid-cols-3">
                    <div className="relative sm:col-span-2">
                        <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input value={filters.search} onChange={(e) => set('search', e.target.value)} placeholder={t('Name, number or mobile')} className="pl-9" aria-label={t('Name, number or mobile')} />
                    </div>
                    <select className={selectClass} value={filters.class} onChange={(e) => set('class', e.target.value)} aria-label={t('Class')}>
                        <option value="">{t('All classes')}</option>
                        {classes.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                    </select>
                </div>
                {applications.data.length === 0 ? (
                    <EmptyState icon={ClipboardList} title={t('No applications')} description={t('Share your public admission form link with parents.')} />
                ) : (
                    <DataTable columns={columns} rows={applications.data} rowKey={(r) => r.id} onRowClick={(r) => router.visit(show.url(r.id))} />
                )}
                <Pagination page={applications} />
            </div>
        </>
    );
}

AdmissionsIndex.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Admissions', href: index() }] };
