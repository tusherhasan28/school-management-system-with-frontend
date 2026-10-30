import { Head, Link, router, usePage } from '@inertiajs/react';
import { BarChart3, CalendarX, ClipboardCheck, Clock } from 'lucide-react';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { StatusBadge } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useT } from '@/lib/i18n';
import { dashboard } from '@/routes';
import { index, report, take } from '@/routes/attendance';

type Props = {
    date: string;
    today: string;
    closed: string | null;
    sections: { id: number; name: string; class: string; canTake: boolean; status: string; marked: number; absent: number }[];
    periods: { id: number; section_id: number; starts_at: string; ends_at: string; subject: string; class: string; section: string; status: string }[];
};

export const attendanceTone: Record<string, string> = { not_taken: 'pending', draft: 'trial', finalized: 'active' };

export default function AttendanceIndex({ date, today, closed, sections, periods }: Props) {
    const t = useT();
    const { locale } = usePage().props;
    const longDate = new Intl.DateTimeFormat(locale === 'bn' ? 'bn-BD' : 'en-GB', { dateStyle: 'full' }).format(new Date(`${date}T00:00:00`));

    return (
        <>
            <Head title={t('Attendance')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('Attendance')}
                    description={longDate}
                    actions={
                        <Input
                            type="date"
                            value={date}
                            max={today}
                            onChange={(e) => router.get(index.url(), { date: e.target.value }, { preserveState: true })}
                            className="w-44"
                            aria-label={t('Date')}
                        />
                    }
                />

                {closed && (
                    <div className="flex items-center gap-3 rounded-xl border border-amber-500/30 bg-amber-50 p-4 text-sm dark:bg-amber-500/10">
                        <CalendarX className="size-5 text-amber-600" />
                        {closed}
                    </div>
                )}

                {periods.length > 0 && (
                    <section className="grid gap-3">
                        <h2 className="font-medium">{t('My periods today')}</h2>
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            {periods.map((p) => (
                                <Link key={p.id} href={take({ query: { section: p.section_id, routine: p.id, date } })} className="rounded-xl border border-border/70 bg-card p-4 transition-colors hover:border-primary/50">
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="flex items-center gap-1.5 text-sm text-muted-foreground"><Clock className="size-3.5" />{p.starts_at}–{p.ends_at}</span>
                                        <StatusBadge status={attendanceTone[p.status]} label={t(p.status)} />
                                    </div>
                                    <p className="mt-2 font-medium">{p.subject}</p>
                                    <p className="text-sm text-muted-foreground">{p.class} · {p.section}</p>
                                </Link>
                            ))}
                        </div>
                    </section>
                )}

                <section className="grid gap-3">
                    <h2 className="font-medium">{t('Daily attendance')}</h2>
                    {sections.length === 0 ? (
                        <EmptyState icon={ClipboardCheck} title={t('No sections assigned to you')} description={t('Ask the office to assign you as class teacher or subject teacher.')} />
                    ) : (
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            {sections.map((s) => (
                                <div key={s.id} className="flex flex-col gap-3 rounded-xl border border-border/70 bg-card p-4">
                                    <div className="flex items-start justify-between gap-2">
                                        <div>
                                            <p className="font-medium">{s.class} · {s.name}</p>
                                            <p className="text-sm text-muted-foreground">
                                                {s.status === 'not_taken' ? t('Not taken yet') : t(':marked marked · :absent absent', { marked: s.marked, absent: s.absent })}
                                            </p>
                                        </div>
                                        <StatusBadge status={attendanceTone[s.status]} label={t(s.status)} />
                                    </div>
                                    <div className="flex gap-2">
                                        <Button size="sm" asChild variant={s.canTake && s.status !== 'finalized' && !closed ? 'default' : 'outline'}>
                                            <Link href={take({ query: { section: s.id, date } })}>{s.canTake && s.status !== 'finalized' && !closed ? t('Take attendance') : t('View')}</Link>
                                        </Button>
                                        <Button size="sm" variant="ghost" asChild>
                                            <Link href={report({ query: { section: s.id, month: date.slice(0, 7) } })}><BarChart3 />{t('Monthly report')}</Link>
                                        </Button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </section>
            </div>
        </>
    );
}

AttendanceIndex.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Attendance', href: index() }] };
