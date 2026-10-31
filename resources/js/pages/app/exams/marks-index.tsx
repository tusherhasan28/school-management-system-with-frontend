import { Head, Link } from '@inertiajs/react';
import { FilePen, FileText } from 'lucide-react';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { StatusBadge } from '@/components/shared/status-badge';
import { formatDate, useT } from '@/lib/i18n';
import { dashboard } from '@/routes';
import { results as resultsRoute } from '@/routes/exams';
import { index, show } from '@/routes/marks';
import { sheetTone } from './show';

type Sheet = { id: number; status: string; exam: string; class: string; section: string; subject: string; exam_date: string | null; can_enter: boolean };

type Props = {
    sheets: Sheet[];
    canVerify: boolean;
    results: { exam_id: number; exam: string; class_id: number; section_id: number; label: string; published: boolean }[];
};

export default function MarksIndex({ sheets, canVerify, results }: Props) {
    const t = useT();
    const groups = Object.entries(
        sheets.reduce<Record<string, Sheet[]>>((acc, s) => ({ ...acc, [s.exam]: [...(acc[s.exam] ?? []), s] }), {}),
    );

    return (
        <>
            <Head title={t('Marks entry')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('Marks entry')}
                    description={canVerify ? t('Every mark sheet in exams open for marks. Submitted sheets wait for your verification.') : t('Mark sheets for the subjects and sections assigned to you.')}
                />

                {groups.length === 0 ? (
                    <EmptyState icon={FilePen} title={t('No mark sheets open')} description={t('Sheets appear here when the office opens marks entry for an exam.')} />
                ) : (
                    groups.map(([exam, list]) => (
                        <section key={exam} className="grid gap-3">
                            <h2 className="font-medium">{exam}</h2>
                            <ul className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                                {list.map((s) => (
                                    <li key={s.id}>
                                        <Link href={show(s.id)} className="flex items-center justify-between gap-3 rounded-xl border border-border/70 bg-card px-4 py-3 transition hover:border-primary/40">
                                            <div className="min-w-0">
                                                <p className="truncate font-medium">{s.subject}</p>
                                                <p className="text-sm text-muted-foreground">{s.class} · {s.section}{s.exam_date ? ` · ${formatDate(s.exam_date)}` : ''}</p>
                                            </div>
                                            <StatusBadge status={sheetTone[s.status]} label={t('sheet_' + s.status)} />
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    ))
                )}

                {results.length > 0 && (
                    <section className="grid gap-3">
                        <h2 className="font-medium">{t('Results for my sections')}</h2>
                        <ul className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                            {results.map((r) => (
                                <li key={`${r.exam_id}-${r.section_id}`}>
                                    <Link href={resultsRoute({ exam: r.exam_id }, { query: { class: r.class_id, section: r.section_id } })} className="flex items-center justify-between gap-3 rounded-xl border border-border/70 bg-card px-4 py-3 transition hover:border-primary/40">
                                        <div>
                                            <p className="font-medium">{r.exam}</p>
                                            <p className="text-sm text-muted-foreground">{r.label}</p>
                                        </div>
                                        <FileText className="size-4 text-muted-foreground" />
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </>
    );
}

MarksIndex.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Marks entry', href: index() }] };
