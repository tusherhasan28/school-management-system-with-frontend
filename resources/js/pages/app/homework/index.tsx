import { Head, Link, router } from '@inertiajs/react';
import { NotebookPen, Plus } from 'lucide-react';
import type { Column } from '@/components/shared/data-table';
import { DataTable } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { selectClass } from '@/components/shared/fields';
import { PageHeader } from '@/components/shared/page-header';
import { Pagination } from '@/components/shared/pagination';
import { StatusBadge } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import { useFilters } from '@/hooks/use-filters';
import { formatDateTime, useT } from '@/lib/i18n';
import { dashboard } from '@/routes';
import { create, index, show } from '@/routes/homework';
import type { AcademicClass, Paginated } from '@/types';
import { cn } from '@/lib/utils';

type Row = { id: number; title: string; status: string; class: string; section: string | null; subject: string; teacher: string | null; assigned_on: string; due_at: string | null; submissions: number; done: number };

export const homeworkTone: Record<string, string> = { draft: 'pending', published: 'active', closed: 'archived' };

export default function HomeworkIndex({ homework, filters: initial, classes, can }: { homework: Paginated<Row>; filters: { class: number | null; status: string }; classes: AcademicClass[]; can: { create: boolean } }) {
    const t = useT();
    const { filters, set } = useFilters(index.url(), { class: initial.class ?? '', status: initial.status });

    const columns: Column<Row>[] = [
        { key: 'title', header: t('Title'), cell: (r) => <Link href={show(r.id)} className="font-medium hover:underline" onClick={(e) => e.stopPropagation()}>{r.title}<span className="block font-normal text-muted-foreground">{r.subject}</span></Link> },
        { key: 'class', header: t('Class'), cell: (r) => `${r.class}${r.section ? ` · ${r.section}` : ''}` },
        { key: 'due', header: t('Due'), cell: (r) => (r.due_at ? formatDateTime(r.due_at) : '—'), hideBelow: 'md' },
        { key: 'progress', header: t('Submitted'), cell: (r) => <span className="tabular-nums">{r.done}/{r.submissions}</span> },
        { key: 'teacher', header: t('Teacher'), cell: (r) => r.teacher ?? '—', hideBelow: 'lg' },
        { key: 'status', header: t('Status'), cell: (r) => <StatusBadge status={homeworkTone[r.status]} label={t(r.status)} /> },
    ];

    return (
        <>
            <Head title={t('Homework')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader title={t('Homework')} description={t('Homework for your classes and subjects this session.')} actions={can.create && <Button asChild><Link href={create()}><Plus />{t('Set homework')}</Link></Button>} />
                <div className="flex flex-wrap gap-2">
                    <select className={cn(selectClass, 'w-auto')} value={filters.class} onChange={(e) => set('class', e.target.value)} aria-label={t('Class')}>
                        <option value="">{t('All classes')}</option>
                        {classes.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                    </select>
                    <select className={cn(selectClass, 'w-auto')} value={filters.status} onChange={(e) => set('status', e.target.value)} aria-label={t('Status')}>
                        <option value="">{t('All statuses')}</option>
                        {['draft', 'published', 'closed'].map((s) => <option key={s} value={s}>{t(s)}</option>)}
                    </select>
                </div>
                {homework.data.length === 0 ? <EmptyState icon={NotebookPen} title={t('No homework yet')} /> : <DataTable columns={columns} rows={homework.data} rowKey={(r) => r.id} onRowClick={(r) => router.visit(show.url(r.id))} />}
                <Pagination page={homework} />
            </div>
        </>
    );
}

HomeworkIndex.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Homework', href: index() }] };
