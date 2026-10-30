import { Head, Link, router } from '@inertiajs/react';
import { ArrowUpRight, FileUp, GraduationCap, Plus, Search } from 'lucide-react';
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
import { index as imports } from '@/routes/imports';
import { index as promotions } from '@/routes/promotions';
import { create, index, show } from '@/routes/students';
import type { AcademicClass, Paginated, SessionOption } from '@/types';

type Row = {
    id: number;
    admission_no: string;
    full_name: string;
    name_bn: string | null;
    gender: string;
    status: string;
    class: string | null;
    section: string | null;
    roll_no: string | null;
};

type Props = {
    students: Paginated<Row>;
    filters: { search: string; session: number | null; class: number | null; section: number | null; status: string };
    sessions: SessionOption[];
    classes: AcademicClass[];
    can: { create: boolean; promote: boolean; import: boolean };
};

export default function StudentsIndex({ students, filters: initial, sessions, classes, can }: Props) {
    const t = useT();
    const { filters, set, setFilters } = useFilters(index.url(), {
        search: initial.search,
        session: initial.session ?? '',
        class: initial.class ?? '',
        section: initial.section ?? '',
        status: initial.status,
    });
    const sections = classes.find((c) => String(c.id) === String(filters.class))?.sections ?? [];

    const columns: Column<Row>[] = [
        { key: 'admission_no', header: t('Admission no.'), cell: (r) => <span className="font-mono text-xs">{r.admission_no}</span> },
        {
            key: 'name',
            header: t('Name'),
            cell: (r) => (
                <Link href={show(r.id)} className="font-medium hover:underline" onClick={(e) => e.stopPropagation()}>
                    {r.full_name}
                    {r.name_bn && <span className="block text-sm font-normal text-muted-foreground">{r.name_bn}</span>}
                </Link>
            ),
        },
        { key: 'class', header: t('Class'), cell: (r) => (r.class ? `${r.class}${r.section ? ` · ${r.section}` : ''}` : <span className="text-muted-foreground">{t('Not enrolled')}</span>) },
        { key: 'roll', header: t('Roll'), cell: (r) => r.roll_no ?? '—', hideBelow: 'sm' },
        { key: 'status', header: t('Status'), cell: (r) => <StatusBadge status={r.status === 'active' ? 'active' : 'archived'} label={t(r.status)} />, hideBelow: 'md' },
    ];

    return (
        <>
            <Head title={t('Students')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('Students')}
                    description={t('Search, filter and open student records.')}
                    actions={
                        <>
                            {can.import && (
                                <Button variant="outline" asChild>
                                    <Link href={imports()}><FileUp />{t('Import')}</Link>
                                </Button>
                            )}
                            {can.promote && (
                                <Button variant="outline" asChild>
                                    <Link href={promotions()}><ArrowUpRight />{t('Promote')}</Link>
                                </Button>
                            )}
                            {can.create && (
                                <Button asChild>
                                    <Link href={create()}><Plus />{t('Admit student')}</Link>
                                </Button>
                            )}
                        </>
                    }
                />

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <div className="relative sm:col-span-2 lg:col-span-2">
                        <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input value={filters.search} onChange={(e) => set('search', e.target.value)} placeholder={t('Name or admission no.')} className="pl-9" aria-label={t('Name or admission no.')} />
                    </div>
                    <select className={selectClass} value={filters.session} onChange={(e) => set('session', e.target.value)} aria-label={t('Session')}>
                        {sessions.map((s) => <option key={s.id} value={s.id}>{s.name}{s.is_current ? ` (${t('current')})` : ''}</option>)}
                    </select>
                    <select className={selectClass} value={filters.class} onChange={(e) => setFilters((f) => ({ ...f, class: e.target.value, section: '' }))} aria-label={t('Class')}>
                        <option value="">{t('All classes')}</option>
                        {classes.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                    </select>
                    <select className={selectClass} value={filters.section} onChange={(e) => set('section', e.target.value)} disabled={!sections.length} aria-label={t('Section')}>
                        <option value="">{t('All sections')}</option>
                        {sections.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                    </select>
                </div>

                {students.data.length === 0 ? (
                    <EmptyState
                        icon={GraduationCap}
                        title={t('No students found')}
                        description={t('Admit students one by one, import a spreadsheet, or convert approved admission applications.')}
                        action={can.create && <Button asChild size="sm"><Link href={create()}>{t('Admit student')}</Link></Button>}
                    />
                ) : (
                    <DataTable columns={columns} rows={students.data} rowKey={(r) => r.id} onRowClick={(r) => router.visit(show.url(r.id))} />
                )}
                <Pagination page={students} />
            </div>
        </>
    );
}

StudentsIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Students', href: index() },
    ],
};
