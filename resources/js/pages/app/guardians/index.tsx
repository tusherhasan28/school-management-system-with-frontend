import { Head, Link, router } from '@inertiajs/react';
import { Search, Users } from 'lucide-react';
import type { Column } from '@/components/shared/data-table';
import { DataTable } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { Pagination } from '@/components/shared/pagination';
import { Input } from '@/components/ui/input';
import { useFilters } from '@/hooks/use-filters';
import { useT } from '@/lib/i18n';
import { dashboard } from '@/routes';
import { index, show } from '@/routes/guardians';
import type { Paginated } from '@/types';

type Row = { id: number; full_name: string; name_bn: string | null; phone: string; email: string | null; occupation: string | null; students_count: number };

export default function GuardiansIndex({ guardians, filters: initial }: { guardians: Paginated<Row>; filters: { search: string } }) {
    const t = useT();
    const { filters, set } = useFilters(index.url(), initial);

    const columns: Column<Row>[] = [
        { key: 'name', header: t('Name'), cell: (r) => <Link href={show(r.id)} className="font-medium hover:underline" onClick={(e) => e.stopPropagation()}>{r.full_name}{r.name_bn && <span className="block font-normal text-muted-foreground">{r.name_bn}</span>}</Link> },
        { key: 'phone', header: t('Mobile'), cell: (r) => <span className="tabular-nums">{r.phone}</span> },
        { key: 'occupation', header: t('Occupation'), cell: (r) => r.occupation ?? '—', hideBelow: 'md' },
        { key: 'students', header: t('Children'), cell: (r) => r.students_count },
    ];

    return (
        <>
            <Head title={t('Guardians')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader title={t('Guardians')} description={t('Parents and guardians. One guardian can be linked to several students.')} />
                <div className="relative max-w-sm">
                    <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input value={filters.search} onChange={(e) => set('search', e.target.value)} placeholder={t('Name or mobile')} className="pl-9" aria-label={t('Name or mobile')} />
                </div>
                {guardians.data.length === 0 ? (
                    <EmptyState icon={Users} title={t('No guardians found')} description={t('Guardians are added together with students.')} />
                ) : (
                    <DataTable columns={columns} rows={guardians.data} rowKey={(r) => r.id} onRowClick={(r) => router.visit(show.url(r.id))} />
                )}
                <Pagination page={guardians} />
            </div>
        </>
    );
}

GuardiansIndex.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Guardians', href: index() }] };
