import { Head, Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle, BookOpen, CalendarCheck, ChevronLeft, ChevronRight, Download, FileText, GraduationCap, Megaphone, Pin, Printer, Wallet } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/shared/empty-state';
import type { Period } from '@/components/shared/week-grid';
import { WeekGrid } from '@/components/shared/week-grid';
import { Button } from '@/components/ui/button';
import { tabUrl } from '@/layouts/portal-layout';
import { formatDate, formatDateTime, formatMoney, useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type Profile = { id: number; name: string; name_bn: string | null; admission_no: string; status: string; class: string | null; section: string | null; roll: string | null; session: string | null };
type Counts = Record<'present' | 'absent' | 'late' | 'leave' | 'half_day', number>;
type HomeworkItem = {
    id: number; title: string; instructions: string | null; subject: string | null; teacher: string | null; assigned_on: string | null; due_at: string | null;
    overdue: boolean; status: string; max_marks: string | null; has_attachment: boolean; attachment_name: string | null;
    submission: string | null; marks: string | null; feedback: string | null;
};
type ResultItem = { exam_id: number; exam: string; published_at: string | null; gpa: string | null; letter_grade: string | null; percentage: string; status: string; obtained: string; full: string; class_position: number | null; section_position: number | null };
type NoticeItem = { id: number; title: string; body: string; is_pinned: boolean; published_on: string; has_attachment?: boolean; attachment_name?: string | null };
type EventItem = { id: number; title: string; starts_on: string; ends_on: string | null; location: string | null };
type FeeTotals = { due: string; overdue: string; open: number };
type Invoice = { id: number; invoice_no: string; billing_period: string | null; issue_date: string; due_date: string; total: string; paid_total: string; balance: string; status: string; overdue: boolean };
type Payment = { id: number; receipt_no: string; method: string; gateway: string | null; amount: string; received_at: string | null; reversal: { type: string } | null };

type Props = {
    tab: string;
    base: string;
    student: Profile;
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    data: any;
};

const statusTone: Record<string, string> = {
    present: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
    late: 'bg-amber-500/10 text-amber-700 dark:text-amber-400',
    half_day: 'bg-amber-500/10 text-amber-700 dark:text-amber-400',
    leave: 'bg-sky-500/10 text-sky-700 dark:text-sky-400',
    absent: 'bg-rose-500/10 text-rose-700 dark:text-rose-400',
};

export default function PortalStudent({ tab, base, student, data }: Props) {
    const t = useT();
    const noticeBase = base.startsWith('/parent') ? '/parent' : '/student';

    return (
        <>
            <Head title={`${t(tabLabel(tab))} · ${student.name}`} />
            <div className="space-y-5">
                <StudentCard student={student} />
                {tab === 'overview' && <Overview data={data} base={base} />}
                {tab === 'attendance' && <Attendance data={data} base={base} />}
                {tab === 'timetable' && (data.periods.length ? <WeekGrid periods={data.periods as Period[]} weekend={data.weekend} /> : <EmptyState title={t('No timetable published yet')} />)}
                {tab === 'homework' && <HomeworkList items={data.items} base={base} />}
                {tab === 'results' && <Results items={data.items} base={base} />}
                {tab === 'fees' && <Fees data={data} base={base} />}
                {tab === 'notices' && <Notices items={data.items} noticeBase={noticeBase} />}
            </div>
        </>
    );
}

function tabLabel(tab: string) {
    return { overview: 'Overview', attendance: 'Attendance', timetable: 'Timetable', homework: 'Homework', results: 'Results', fees: 'Fees', notices: 'Notices' }[tab] ?? tab;
}

function StudentCard({ student }: { student: Profile }) {
    const t = useT();
    const meta = [student.class, student.section && `${t('Section')} ${student.section}`, student.roll && `${t('Roll')} ${student.roll}`].filter(Boolean).join(' · ');

    return (
        <section className="rounded-xl border border-border/70 bg-card p-4">
            <h1 className="text-lg font-semibold">{student.name}</h1>
            {student.name_bn && <p className="text-sm text-muted-foreground" lang="bn">{student.name_bn}</p>}
            <p className="mt-1 text-sm text-muted-foreground">{meta || '—'}</p>
            <p className="text-xs text-muted-foreground">{t('Admission no.')} {student.admission_no}{student.session ? ` · ${student.session}` : ''}</p>
        </section>
    );
}

function Card({ title, icon: Icon, action, children, className }: { title: string; icon?: typeof Wallet; action?: React.ReactNode; children: React.ReactNode; className?: string }) {
    return (
        <section className={cn('rounded-xl border border-border/70 bg-card', className)}>
            <header className="flex items-center justify-between gap-2 border-b border-border/70 px-4 py-3">
                <h2 className="flex items-center gap-2 font-medium">{Icon && <Icon className="size-4 text-muted-foreground" aria-hidden />}{title}</h2>
                {action}
            </header>
            <div className="p-4">{children}</div>
        </section>
    );
}

function CountChips({ counts }: { counts: Counts }) {
    const t = useT();

    return (
        <div className="flex flex-wrap gap-2 text-sm">
            {(['present', 'absent', 'late', 'leave', 'half_day'] as const).map((s) => (
                <span key={s} className={cn('rounded-lg px-2.5 py-1', statusTone[s])}>{t(s)}: <b className="tabular-nums">{counts[s] ?? 0}</b></span>
            ))}
        </div>
    );
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
function Overview({ data, base }: { data: any; base: string }) {
    const t = useT();
    const result: ResultItem | null = data.result;

    return (
        <div className="grid gap-4 md:grid-cols-2">
            <Card title={t('Attendance this month')} icon={CalendarCheck} action={<Link className="text-sm text-primary" href={tabUrl(base, 'attendance')}>{t('View')}</Link>}>
                <p className="mb-3 text-sm">
                    {t('Today')}: {data.attendance.today ? <b>{t(data.attendance.today)}</b> : <span className="text-muted-foreground">{data.attendance.closed ? t('School closed') : t('Not marked yet')}</span>}
                </p>
                <CountChips counts={data.attendance.month} />
            </Card>

            <Card title={t('Fees')} icon={Wallet} action={<Link className="text-sm text-primary" href={tabUrl(base, 'fees')}>{t('View')}</Link>}>
                <p className="text-2xl font-semibold tabular-nums">{formatMoney(data.fees.due)}</p>
                <p className="text-sm text-muted-foreground">{t('Due on :count open invoices', { count: String(data.fees.open) })}</p>
                {Number(data.fees.overdue) > 0 && (
                    <p className="mt-2 flex items-center gap-1.5 text-sm text-rose-700 dark:text-rose-400"><AlertTriangle className="size-4" />{t('Overdue')}: {formatMoney(data.fees.overdue)}</p>
                )}
            </Card>

            <Card title={t("Today's classes")} icon={BookOpen} action={<Link className="text-sm text-primary" href={tabUrl(base, 'timetable')}>{t('Timetable')}</Link>}>
                {data.today.length === 0 ? (
                    <p className="text-sm text-muted-foreground">{t('No classes today')}</p>
                ) : (
                    <ul className="divide-y divide-border/70 text-sm">
                        {data.today.map((p: Period) => (
                            <li key={p.id} className="flex justify-between gap-3 py-2">
                                <span className="font-medium">{p.subject}</span>
                                <span className="text-muted-foreground tabular-nums">{p.starts_at}–{p.ends_at}</span>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            <Card title={t('Homework due')} icon={BookOpen} action={<Link className="text-sm text-primary" href={tabUrl(base, 'homework')}>{t('View')}</Link>}>
                {data.homework.next.length === 0 ? (
                    <p className="text-sm text-muted-foreground">{t('Nothing due')}</p>
                ) : (
                    <ul className="divide-y divide-border/70 text-sm">
                        {data.homework.next.map((h: HomeworkItem) => (
                            <li key={h.id} className="py-2">
                                <p className="font-medium">{h.title}</p>
                                <p className="text-muted-foreground">{h.subject} · {t('Due')} {formatDateTime(h.due_at)}</p>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            <Card title={t('Latest result')} icon={GraduationCap} action={<Link className="text-sm text-primary" href={tabUrl(base, 'results')}>{t('View')}</Link>}>
                {result ? (
                    <div className="flex items-center justify-between gap-3">
                        <div>
                            <p className="font-medium">{result.exam}</p>
                            <p className="text-sm text-muted-foreground">{result.letter_grade ?? '—'}{result.gpa ? ` · GPA ${result.gpa}` : ''} · {result.percentage}%</p>
                        </div>
                        <Button size="sm" variant="outline" asChild><a href={`${base}/results/${result.exam_id}`}><FileText />{t('Report card')}</a></Button>
                    </div>
                ) : (
                    <p className="text-sm text-muted-foreground">{t('No published results yet')}</p>
                )}
            </Card>

            <Card title={t('Notices')} icon={Megaphone} action={<Link className="text-sm text-primary" href={tabUrl(base, 'notices')}>{t('View')}</Link>}>
                {data.notices.length === 0 ? (
                    <p className="text-sm text-muted-foreground">{t('No notices')}</p>
                ) : (
                    <ul className="divide-y divide-border/70 text-sm">
                        {data.notices.map((n: NoticeItem) => (
                            <li key={n.id} className="py-2">
                                <p className="flex items-center gap-1.5 font-medium">{n.is_pinned && <Pin className="size-3.5 text-primary" />}{n.title}</p>
                                <p className="text-muted-foreground">{formatDate(n.published_on)}</p>
                            </li>
                        ))}
                    </ul>
                )}
                {data.events.length > 0 && (
                    <div className="mt-3 border-t border-border/70 pt-3">
                        <p className="mb-1 text-sm font-medium">{t('Upcoming events')}</p>
                        <ul className="space-y-1 text-sm">
                            {data.events.map((e: EventItem) => (
                                <li key={e.id} className="flex justify-between gap-3"><span>{e.title}</span><span className="text-muted-foreground">{formatDate(e.starts_on)}</span></li>
                            ))}
                        </ul>
                    </div>
                )}
            </Card>
        </div>
    );
}

function Attendance({ data, base }: { data: { month: string; prev: string; next: string | null; counts: Counts; days: { date: string; status: string; remarks: string | null }[] }; base: string }) {
    const t = useT();
    const url = tabUrl(base, 'attendance');
    const { locale } = usePage().props;
    const label = new Intl.DateTimeFormat(locale === 'bn' ? 'bn-BD' : 'en-GB', { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(`${data.month}-01T00:00:00Z`));

    return (
        <Card
            title={label}
            icon={CalendarCheck}
            action={
                <div className="flex gap-1">
                    <Button size="icon" variant="ghost" aria-label={t('Previous month')} onClick={() => router.get(url, { month: data.prev }, { preserveScroll: true })}><ChevronLeft /></Button>
                    <Button size="icon" variant="ghost" aria-label={t('Next month')} disabled={!data.next} onClick={() => data.next && router.get(url, { month: data.next }, { preserveScroll: true })}><ChevronRight /></Button>
                </div>
            }
        >
            <CountChips counts={data.counts} />
            {data.days.length === 0 ? (
                <p className="mt-4 text-sm text-muted-foreground">{t('No attendance recorded this month')}</p>
            ) : (
                <ul className="mt-4 divide-y divide-border/70 text-sm">
                    {data.days.map((d) => (
                        <li key={d.date} className="flex items-center justify-between gap-3 py-2">
                            <span>{formatDate(d.date)}</span>
                            <span className="text-right">
                                <span className={cn('rounded-md px-2 py-0.5', statusTone[d.status])}>{t(d.status)}</span>
                                {d.remarks && <span className="mt-0.5 block text-xs text-muted-foreground">{d.remarks}</span>}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}

function HomeworkList({ items, base }: { items: HomeworkItem[]; base: string }) {
    const t = useT();

    if (items.length === 0) {
        return <EmptyState icon={BookOpen} title={t('No homework yet')} />;
    }

    return (
        <ul className="space-y-3">
            {items.map((h) => (
                <li key={h.id} className="rounded-xl border border-border/70 bg-card p-4">
                    <div className="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <p className="font-medium">{h.title}</p>
                            <p className="text-sm text-muted-foreground">{[h.subject, h.teacher].filter(Boolean).join(' · ')}</p>
                        </div>
                        <span className={cn('rounded-md px-2 py-0.5 text-xs', h.submission === 'graded' ? statusTone.present : h.overdue && !h.submission ? statusTone.absent : 'bg-muted text-muted-foreground')}>
                            {h.submission ? t(h.submission) : h.overdue ? t('Overdue') : t('pending')}
                        </span>
                    </div>
                    {h.instructions && <p className="mt-2 text-sm whitespace-pre-line">{h.instructions}</p>}
                    <p className="mt-2 text-xs text-muted-foreground">
                        {t('Assigned')} {formatDate(h.assigned_on)}{h.due_at ? ` · ${t('Due')} ${formatDateTime(h.due_at)}` : ''}
                    </p>
                    {h.submission === 'graded' && (
                        <p className="mt-2 text-sm">{t('Marks')}: <b>{h.marks ?? '—'}</b>{h.max_marks ? ` / ${h.max_marks}` : ''}{h.feedback ? ` — ${h.feedback}` : ''}</p>
                    )}
                    {h.has_attachment && (
                        <Button size="sm" variant="outline" className="mt-3" asChild>
                            <a href={`${base}/homework/${h.id}/attachment`}><Download />{h.attachment_name ?? t('Attachment')}</a>
                        </Button>
                    )}
                </li>
            ))}
        </ul>
    );
}

function Results({ items, base }: { items: ResultItem[]; base: string }) {
    const t = useT();

    if (items.length === 0) {
        return <EmptyState icon={GraduationCap} title={t('No published results yet')} description={t('Results appear here once the school publishes them.')} />;
    }

    return (
        <ul className="space-y-3">
            {items.map((r) => (
                <li key={r.exam_id} className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border/70 bg-card p-4">
                    <div>
                        <p className="font-medium">{r.exam}</p>
                        <p className="text-sm text-muted-foreground">
                            {t('result_' + r.status)} · {r.letter_grade ?? '—'}{r.gpa ? ` · GPA ${r.gpa}` : ''} · {r.obtained}/{r.full} ({r.percentage}%)
                        </p>
                        {(r.section_position || r.class_position) && (
                            <p className="text-xs text-muted-foreground">
                                {r.section_position ? `${t('Section position')}: ${r.section_position}` : ''}{r.section_position && r.class_position ? ' · ' : ''}{r.class_position ? `${t('Class position')}: ${r.class_position}` : ''}
                            </p>
                        )}
                    </div>
                    <Button size="sm" variant="outline" asChild><a href={`${base}/results/${r.exam_id}`}><FileText />{t('Report card')}</a></Button>
                </li>
            ))}
        </ul>
    );
}

function Fees({ data, base }: { data: { totals: FeeTotals; invoices: Invoice[]; payments: Payment[]; gateways: Record<string, string> }; base: string }) {
    const t = useT();
    const gateways = Object.entries(data.gateways);
    const [paying, setPaying] = useState<number | null>(null);

    const pay = (invoice: Invoice, gateway: string) => {
        setPaying(invoice.id);
        router.post(`${base}/invoices/${invoice.id}/pay`, { gateway }, { onFinish: () => setPaying(null) });
    };

    return (
        <div className="space-y-4">
            <div className="grid gap-3 sm:grid-cols-2">
                <div className="rounded-xl border border-border/70 bg-card p-4">
                    <p className="text-sm text-muted-foreground">{t('Total due')}</p>
                    <p className="text-2xl font-semibold tabular-nums">{formatMoney(data.totals.due)}</p>
                </div>
                <div className="rounded-xl border border-border/70 bg-card p-4">
                    <p className="text-sm text-muted-foreground">{t('Overdue')}</p>
                    <p className={cn('text-2xl font-semibold tabular-nums', Number(data.totals.overdue) > 0 && 'text-rose-700 dark:text-rose-400')}>{formatMoney(data.totals.overdue)}</p>
                </div>
            </div>

            <Card title={t('Invoices')} icon={FileText}>
                {data.invoices.length === 0 ? (
                    <p className="text-sm text-muted-foreground">{t('No invoices yet')}</p>
                ) : (
                    <ul className="divide-y divide-border/70">
                        {data.invoices.map((i) => (
                            <li key={i.id} className="flex flex-wrap items-center justify-between gap-3 py-3 text-sm">
                                <div>
                                    <p className="font-medium">{i.billing_period ?? i.invoice_no}</p>
                                    <p className="text-muted-foreground">
                                        {i.invoice_no} · {t('Due')} {formatDate(i.due_date)}
                                        {i.overdue && <span className="ml-1 text-rose-700 dark:text-rose-400">({t('Overdue')})</span>}
                                    </p>
                                    <p className="text-muted-foreground">{t('Total')} {formatMoney(i.total)} · {t('Paid')} {formatMoney(i.paid_total)} · <b className="text-foreground">{t('Balance')} {formatMoney(i.balance)}</b></p>
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    <Button size="sm" variant="outline" asChild><a href={`${base}/invoices/${i.id}`}><Printer />{t('Invoice')}</a></Button>
                                    {Number(i.balance) > 0 &&
                                        gateways.map(([key, label]) => (
                                            <Button key={key} size="sm" disabled={paying !== null} onClick={() => pay(i, key)}>
                                                <Wallet />{t('Pay with :gateway', { gateway: label })}
                                            </Button>
                                        ))}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
                {gateways.length === 0 && data.totals.open > 0 && <p className="mt-3 text-xs text-muted-foreground">{t('Please pay at the school office.')}</p>}
            </Card>

            <Card title={t('Payments')} icon={Wallet}>
                {data.payments.length === 0 ? (
                    <p className="text-sm text-muted-foreground">{t('No payments yet')}</p>
                ) : (
                    <ul className="divide-y divide-border/70">
                        {data.payments.map((p) => (
                            <li key={p.id} className="flex flex-wrap items-center justify-between gap-3 py-3 text-sm">
                                <div>
                                    <p className="font-medium tabular-nums">{formatMoney(p.amount)} {p.reversal && <span className="ml-1 text-xs text-rose-700 dark:text-rose-400">({t('reverse_' + p.reversal.type)})</span>}</p>
                                    <p className="text-muted-foreground">{p.receipt_no} · {formatDateTime(p.received_at)} · {t('method_' + p.method)}</p>
                                </div>
                                <Button size="sm" variant="outline" asChild><a href={`${base}/receipts/${p.id}`}><Printer />{t('Receipt')}</a></Button>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>
        </div>
    );
}

function Notices({ items, noticeBase }: { items: NoticeItem[]; noticeBase: string }) {
    const t = useT();

    if (items.length === 0) {
        return <EmptyState icon={Megaphone} title={t('No notices')} />;
    }

    return (
        <ul className="space-y-3">
            {items.map((n) => (
                <li key={n.id} className="rounded-xl border border-border/70 bg-card p-4">
                    <p className="flex items-center gap-1.5 font-medium">{n.is_pinned && <Pin className="size-3.5 text-primary" aria-label={t('Pinned')} />}{n.title}</p>
                    <p className="text-xs text-muted-foreground">{formatDate(n.published_on)}</p>
                    {/* Server-rendered Markdown with raw HTML escaped and unsafe links removed. */}
                    <div className="prose-site mt-2 text-sm" dangerouslySetInnerHTML={{ __html: n.body }} />
                    {n.has_attachment && (
                        <Button size="sm" variant="outline" className="mt-3" asChild>
                            <a href={`${noticeBase}/notices/${n.id}/attachment`}><Download />{n.attachment_name ?? t('Attachment')}</a>
                        </Button>
                    )}
                </li>
            ))}
        </ul>
    );
}
