import { Head, Link } from '@inertiajs/react';
import { BookOpen, CalendarCheck, CalendarRange, ClipboardList, Download, Megaphone, Pin, Users } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { PageHeader } from '@/components/shared/page-header';
import { Button } from '@/components/ui/button';
import { formatDate, formatDateTime, useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { take } from '@/routes/attendance';
import { create as createHomework, show as showHomework } from '@/routes/homework';
import { index as marksIndex, show as showSheet } from '@/routes/marks';
import { dashboard } from '@/routes/teacher';
import { attachment as noticeAttachment } from '@/routes/teacher/notices';
import { mine } from '@/routes/timetable';

type Props = {
    teacher: { name: string; designation: string | null };
    today: { date: string; closed: string | null };
    periods: { id: number; starts_at: string; ends_at: string; subject: string; class: string; section: string; section_id: number; room: string | null }[];
    attendance: { section_id: number; class: string; section: string; state: 'pending' | 'taken' | 'finalized' }[];
    homework: { id: number; title: string; subject: string; class: string; section: string | null; due_at: string | null; status: string; submitted: number }[];
    sheets: { id: number; exam: string; subject: string; class: string; section: string | null }[];
    classes: { id: number; type: string; class: string; section: string | null; subject: string | null }[];
    notices: { id: number; title: string; body: string; is_pinned: boolean; published_on: string; has_attachment: boolean; attachment_name: string | null }[];
    can: { homework: boolean; marks: boolean; attendance: boolean };
};

function Panel({ title, icon: Icon, action, children, className }: { title: string; icon: LucideIcon; action?: ReactNode; children: ReactNode; className?: string }) {
    return (
        <section className={cn('rounded-xl border border-border/70 bg-card', className)}>
            <header className="flex items-center justify-between gap-2 border-b border-border/70 px-4 py-3">
                <h2 className="flex items-center gap-2 font-medium"><Icon className="size-4 text-muted-foreground" aria-hidden />{title}</h2>
                {action}
            </header>
            <div className="p-4">{children}</div>
        </section>
    );
}

export default function TeacherDashboard({ teacher, today, periods, attendance, homework, sheets, classes, notices, can }: Props) {
    const t = useT();
    const stateTone = { pending: 'text-amber-700 dark:text-amber-400', taken: 'text-sky-700 dark:text-sky-400', finalized: 'text-emerald-700 dark:text-emerald-400' };
    const stateLabel = { pending: t('Not taken'), taken: t('Taken'), finalized: t('Finalized') };

    return (
        <>
            <Head title={t('Teacher dashboard')} />
            <div className="flex flex-1 flex-col gap-4 p-4 md:p-6">
                <PageHeader title={t('Good day, :name', { name: teacher.name })} description={[teacher.designation, formatDate(today.date)].filter(Boolean).join(' · ')} />

                {today.closed && <p className="rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-2 text-sm">{t('School is closed today')}: {t(today.closed)}</p>}

                <div className="grid gap-4 lg:grid-cols-2">
                    <Panel title={t("Today's periods")} icon={CalendarRange} action={<Link className="text-sm text-primary" href={mine()}>{t('My timetable')}</Link>}>
                        {periods.length === 0 ? (
                            <p className="text-sm text-muted-foreground">{t('No periods today')}</p>
                        ) : (
                            <ul className="divide-y divide-border/70 text-sm">
                                {periods.map((p) => (
                                    <li key={p.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                                        <div>
                                            <p className="font-medium">{p.subject}</p>
                                            <p className="text-muted-foreground">{p.class} · {p.section}{p.room ? ` · ${p.room}` : ''}</p>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <span className="tabular-nums text-muted-foreground">{p.starts_at}–{p.ends_at}</span>
                                            {can.attendance && !today.closed && (
                                                <Button size="sm" variant="outline" asChild><Link href={take({ query: { section: p.section_id, date: today.date, routine: p.id } })}>{t('Attendance')}</Link></Button>
                                            )}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>

                    <Panel title={t('Daily attendance')} icon={CalendarCheck}>
                        {attendance.length === 0 ? (
                            <p className="text-sm text-muted-foreground">{t('You are not class teacher of any section')}</p>
                        ) : (
                            <ul className="divide-y divide-border/70 text-sm">
                                {attendance.map((a) => (
                                    <li key={a.section_id} className="flex items-center justify-between gap-2 py-2">
                                        <span>{a.class} · {a.section}</span>
                                        <span className="flex items-center gap-2">
                                            <span className={stateTone[a.state]}>{stateLabel[a.state]}</span>
                                            {!today.closed && a.state !== 'finalized' && (
                                                <Button size="sm" asChild><Link href={take({ query: { section: a.section_id, date: today.date } })}>{a.state === 'pending' ? t('Take') : t('Review')}</Link></Button>
                                            )}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>

                    <Panel title={t('Homework')} icon={BookOpen} action={can.homework && <Link className="text-sm text-primary" href={createHomework()}>{t('New homework')}</Link>}>
                        {homework.length === 0 ? (
                            <p className="text-sm text-muted-foreground">{t('No homework yet')}</p>
                        ) : (
                            <ul className="divide-y divide-border/70 text-sm">
                                {homework.map((h) => (
                                    <li key={h.id} className="flex items-center justify-between gap-2 py-2">
                                        <div className="min-w-0">
                                            <Link href={showHomework(h.id)} className="font-medium hover:underline">{h.title}</Link>
                                            <p className="text-muted-foreground">{h.subject} · {h.class}{h.section ? ` · ${h.section}` : ''}{h.due_at ? ` · ${t('Due')} ${formatDateTime(h.due_at)}` : ''}</p>
                                        </div>
                                        <span className="shrink-0 text-muted-foreground">{t(':count submitted', { count: String(h.submitted) })}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>

                    <Panel title={t('Mark sheets to fill')} icon={ClipboardList} action={can.marks && <Link className="text-sm text-primary" href={marksIndex()}>{t('All mark sheets')}</Link>}>
                        {sheets.length === 0 ? (
                            <p className="text-sm text-muted-foreground">{t('No open mark sheets')}</p>
                        ) : (
                            <ul className="divide-y divide-border/70 text-sm">
                                {sheets.map((s) => (
                                    <li key={s.id} className="flex items-center justify-between gap-2 py-2">
                                        <div>
                                            <p className="font-medium">{s.subject}</p>
                                            <p className="text-muted-foreground">{s.exam} · {s.class}{s.section ? ` · ${s.section}` : ''}</p>
                                        </div>
                                        <Button size="sm" variant="outline" asChild><Link href={showSheet(s.id)}>{t('Enter marks')}</Link></Button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>

                    <Panel title={t('My classes')} icon={Users}>
                        {classes.length === 0 ? (
                            <p className="text-sm text-muted-foreground">{t('No classes assigned yet')}</p>
                        ) : (
                            <ul className="flex flex-wrap gap-2 text-sm">
                                {classes.map((c) => (
                                    <li key={c.id} className="rounded-lg border px-3 py-1.5">
                                        {c.class}{c.section ? ` · ${c.section}` : ''} — {c.type === 'class_teacher' ? t('Class teacher') : c.subject}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>

                    <Panel title={t('Staff notices')} icon={Megaphone}>
                        {notices.length === 0 ? (
                            <p className="text-sm text-muted-foreground">{t('No notices')}</p>
                        ) : (
                            <ul className="space-y-3">
                                {notices.map((n) => (
                                    <li key={n.id}>
                                        <p className="flex items-center gap-1.5 text-sm font-medium">{n.is_pinned && <Pin className="size-3.5 text-primary" />}{n.title}</p>
                                        <p className="text-xs text-muted-foreground">{formatDate(n.published_on)}</p>
                                        <div className="prose-site mt-1 text-sm" dangerouslySetInnerHTML={{ __html: n.body }} />
                                        {n.has_attachment && (
                                            <a className="mt-1 inline-flex items-center gap-1 text-sm text-primary" href={noticeAttachment(n.id).url}><Download className="size-3.5" />{n.attachment_name ?? t('Attachment')}</a>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>
                </div>
            </div>
        </>
    );
}

TeacherDashboard.layout = { breadcrumbs: [{ title: 'Teacher dashboard', href: dashboard() }] };
