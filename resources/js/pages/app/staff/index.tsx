import { Head, Link, router } from '@inertiajs/react';
import { Plus, Search, UserRound } from 'lucide-react';
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
import { useT } from '@/lib/i18n';
import { dashboard } from '@/routes';
import { create, edit, index } from '@/routes/staff';
import type { Paginated } from '@/types';

type Row = { id: number; employee_no: string; full_name: string; name_bn: string | null; staff_type: string; designation: string | null; phone: string | null; email: string | null; status: string; login: string | null };

type Props = { staff: Paginated<Row>; filters: { search: string; type: string; status: string }; can: { create: boolean } };

export default function StaffIndex({ staff, filters: initial, can }: Props) {
    const t = useT();
    const { filters, set } = useFilters(index.url(), initial);

    const columns: Column<Row>[] = [
        { key: 'no', header: t('Employee no.'), cell: (r) => <span className="font-mono text-xs">{r.employee_no}</span>, hideBelow: 'sm' },
        { key: 'name', header: t('Name'), cell: (r) => <Link href={edit(r.id)} className="font-medium hover:underline" onClick={(e) => e.stopPropagation()}>{r.full_name}{r.name_bn && <span className="block font-normal text-muted-foreground">{r.name_bn}</span>}</Link> },
        { key: 'role', header: t('Role'), cell: (r) => <>{t(r.staff_type)}{r.designation && <span className="block text-muted-foreground">{r.designation}</span>}</> },
        { key: 'phone', header: t('Mobile'), cell: (r) => r.phone ?? '—', hideBelow: 'md' },
        { key: 'login', header: t('Login'), cell: (r) => (r.login ? <StatusBadge status={r.login === 'active' ? 'active' : 'pending'} label={t(r.login)} /> : <span className="text-muted-foreground">—</span>), hideBelow: 'lg' },
        { key: 'status', header: t('Status'), cell: (r) => <StatusBadge status={r.status === 'active' ? 'active' : 'archived'} label={t(r.status)} /> },
    ];

    return (
        <>
            <Head title={t('Teachers & staff')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('Teachers & staff')}
                    description={t('Profiles of teachers and non-teaching staff. Give a login from their profile.')}
                    actions={can.create && <Button asChild><Link href={create()}><Plus />{t('Add staff')}</Link></Button>}
                />
                <div className="grid gap-3 sm:grid-cols-3">
                    <div className="relative">
                        <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input value={filters.search} onChange={(e) => set('search', e.target.value)} placeholder={t('Name, number or mobile')} className="pl-9" aria-label={t('Name, number or mobile')} />
                    </div>
                    <select className={selectClass} value={filters.type} onChange={(e) => set('type', e.target.value)} aria-label={t('Type')}>
                        <option value="">{t('Teachers and staff')}</option>
                        <option value="teacher">{t('teacher')}</option>
                        <option value="staff">{t('staff')}</option>
                    </select>
                    <select className={selectClass} value={filters.status} onChange={(e) => set('status', e.target.value)} aria-label={t('Status')}>
                        <option value="">{t('All statuses')}</option>
                        {['active', 'on_leave', 'inactive'].map((s) => <option key={s} value={s}>{t(s)}</option>)}
                    </select>
                </div>
                {staff.data.length === 0 ? (
                    <EmptyState icon={UserRound} title={t('No staff found')} />
                ) : (
                    <DataTable columns={columns} rows={staff.data} rowKey={(r) => r.id} onRowClick={(r) => router.visit(edit.url(r.id))} />
                )}
                <Pagination page={staff} />
            </div>
        </>
    );
}

StaffIndex.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Teachers & staff', href: index() }] };
