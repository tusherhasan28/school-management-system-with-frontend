import { Head, Link, router } from '@inertiajs/react';
import { Award, Printer } from 'lucide-react';
import { EmptyState } from '@/components/shared/empty-state';
import { selectClass } from '@/components/shared/fields';
import { PageHeader } from '@/components/shared/page-header';
import { StatCard } from '@/components/shared/stat-card';
import { StatusBadge } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import { formatDateTime, useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index, reportCards, results, show } from '@/routes/exams';

type SubjectCell = { obtained: string; grade: string; passed: boolean; status: string; counted_as: string };
type Row = {
    student_id: number; name: string; admission_no: string; roll: string | null; section: string | null; full_marks: string; obtained_marks: string;
    percentage: string; gpa: string | null; letter_grade: string | null; status: string; failed_subjects: number; position: number | null; subjects: Record<string, SubjectCell>;
};

type Props = {
    exam: { id: number; name: string; title: string; status: string; published: boolean; published_at: string | null; version: number | null };
    classes: { id: number; name: string; sections: { id: number; name: string }[] }[];
    filters: { class: number | null; section: number | null };
    subjects: { id: number; name: string; type: string }[];
    rows: Row[];
    stats: { total: number; pass: number; fail: number; grades: Record<string, number> };
};

export default function Results({ exam, classes, filters, subjects, rows, stats }: Props) {
    const t = useT();
    const current = classes.find((c) => c.id === filters.class);
    const go = (q: Record<string, number | string | null>) => router.get(results.url(exam.id), { class: filters.class, section: filters.section, ...q }, { preserveState: true, preserveScroll: true });
    const hasGpa = rows.some((r) => r.gpa !== null);
    const query = { class: filters.class ?? undefined, section: filters.section ?? undefined };
    const maxGrade = Math.max(1, ...Object.values(stats.grades));

    return (
        <>
            <Head title={`${exam.title} · ${t('Results')}`} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={exam.title}
                    description={exam.published ? t('Published :at', { at: formatDateTime(exam.published_at) }) : t('Draft results (version :version), not visible to families yet.', { version: exam.version ?? '—' })}
                    actions={
                        <>
                            <select className={cn(selectClass, 'w-36')} value={filters.class ?? ''} onChange={(e) => go({ class: e.target.value, section: null })} aria-label={t('Class')}>
                                {classes.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                            </select>
                            <select className={cn(selectClass, 'w-32')} value={filters.section ?? ''} onChange={(e) => go({ section: e.target.value || null })} aria-label={t('Section')}>
                                {(current?.sections.length ?? 0) > 1 || !current ? <option value="">{t('All sections')}</option> : null}
                                {current?.sections.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                            </select>
                            {rows.length > 0 && <Button asChild><a href={reportCards.url(exam.id, { query })} target="_blank" rel="noreferrer"><Printer />{t('Print report cards')}</a></Button>}
                        </>
                    }
                />

                {rows.length === 0 ? (
                    <EmptyState icon={Award} title={t('No results yet')} description={t('Results appear here after they are calculated.')} action={<Button variant="outline" asChild><Link href={show(exam.id)}>{t('Back to exam')}</Link></Button>} />
                ) : (
                    <>
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <StatCard label={t('Students')} value={stats.total} />
                            <StatCard label={t('Passed')} value={stats.pass} tone="success" />
                            <StatCard label={t('Not passed')} value={stats.fail} tone="warning" />
                            <StatCard label={t('Pass rate')} value={stats.total ? `${Math.round((stats.pass / stats.total) * 1000) / 10}%` : '—'} />
                        </div>

                        <section className="rounded-xl border border-border/70 bg-card p-4">
                            <h2 className="mb-3 text-sm font-medium">{t('Grade distribution')}</h2>
                            <div className="flex h-28 items-end gap-3" role="img" aria-label={Object.entries(stats.grades).map(([g, n]) => `${g}: ${n}`).join(', ')}>
                                {Object.entries(stats.grades).map(([g, n]) => (
                                    <div key={g} className="flex flex-1 flex-col items-center gap-1">
                                        <span className="text-xs tabular-nums text-muted-foreground">{n}</span>
                                        <div className={cn('w-full max-w-12 rounded-t-md', g === 'F' ? 'bg-rose-400/80' : 'bg-primary/70')} style={{ height: `${(n / maxGrade) * 80}px` }} />
                                        <span className="text-xs font-medium">{g}</span>
                                    </div>
                                ))}
                            </div>
                        </section>

                        <section className="overflow-hidden rounded-xl border border-border/70 bg-card">
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead className="bg-muted/50 text-left text-xs text-muted-foreground uppercase">
                                        <tr>
                                            <th className="px-3 py-2">{t('Position')}</th>
                                            <th className="px-3 py-2">{t('Student')}</th>
                                            {subjects.map((s) => <th key={s.id} className="max-w-28 px-3 py-2 text-center leading-tight normal-case">{s.name}</th>)}
                                            <th className="px-3 py-2 text-right">{t('Total')}</th>
                                            {hasGpa && <th className="px-3 py-2 text-right">{t('GPA')}</th>}
                                            <th className="px-3 py-2 text-center">{t('Grade')}</th>
                                            <th className="px-3 py-2">{t('Result')}</th>
                                            <th />
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-border/70">
                                        {rows.map((r) => (
                                            <tr key={r.student_id}>
                                                <td className="px-3 py-2 font-medium tabular-nums">{r.position ?? '—'}</td>
                                                <td className="px-3 py-2">
                                                    <p className="font-medium">{r.name}</p>
                                                    <p className="text-xs text-muted-foreground">{[r.section, r.roll && `${t('Roll')} ${r.roll}`].filter(Boolean).join(' · ')}</p>
                                                </td>
                                                {subjects.map((s) => {
                                                    const cell = r.subjects[s.id];

                                                    return (
                                                        <td key={s.id} className={cn('px-3 py-2 text-center tabular-nums', cell && !cell.passed && cell.counted_as !== 'non_gpa' && 'text-destructive')}>
                                                            {cell ? (cell.status === 'absent' ? t('Abs') : <>{Number(cell.obtained)} <span className="text-xs text-muted-foreground">{cell.grade}</span></>) : '—'}
                                                        </td>
                                                    );
                                                })}
                                                <td className="px-3 py-2 text-right tabular-nums">{Number(r.obtained_marks)}/{Number(r.full_marks)}</td>
                                                {hasGpa && <td className="px-3 py-2 text-right font-medium tabular-nums">{r.gpa ?? '—'}</td>}
                                                <td className="px-3 py-2 text-center font-medium">{r.letter_grade ?? '—'}</td>
                                                <td className="px-3 py-2"><StatusBadge status={r.status === 'pass' ? 'active' : 'suspended'} label={t('result_' + r.status)} /></td>
                                                <td className="px-3 py-2 text-right">
                                                    <a href={reportCards.url(exam.id, { query: { ...query, student: r.student_id } })} target="_blank" rel="noreferrer" className="inline-flex text-muted-foreground hover:text-foreground" aria-label={t('Report card for :name', { name: r.name })}>
                                                        <Printer className="size-4" />
                                                    </a>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    </>
                )}
            </div>
        </>
    );
}

Results.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Exams & results', href: index() }] };
