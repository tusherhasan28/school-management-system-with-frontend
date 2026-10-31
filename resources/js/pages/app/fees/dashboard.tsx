import { Form, Head, Link } from '@inertiajs/react';
import { AlertTriangle, Banknote, CalendarClock, FileText, Search, Settings2, Wallet } from 'lucide-react';
import { PageHeader } from '@/components/shared/page-header';
import { StatCard } from '@/components/shared/stat-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDateTime, formatMoney, useT } from '@/lib/i18n';
import { dashboard as home } from '@/routes';
import { dashboard, reports, setup } from '@/routes/fees';
import { index as invoices } from '@/routes/fees/invoices';
import { index as payments } from '@/routes/fees/payments';
import { show as ledger } from '@/routes/fees/students';
import type { Payment } from './shared';

type Props = {
    stats: { today: string; month: string; outstanding: string; overdue: string; overdue_count: number; pending_online: number };
    recent: Payment[];
    q: string;
    matches: { id: number; full_name: string; name_bn: string | null; admission_no: string; status: string }[];
    can: { manage: boolean; reports: boolean };
};

export default function FeesDashboard({ stats, recent, q, matches, can }: Props) {
    const t = useT();

    return (
        <>
            <Head title={t('Fees')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('Fees')}
                    description={t('Collect fees, follow up dues and keep every taka accounted for.')}
                    actions={
                        <>
                            <Button variant="outline" asChild><Link href={invoices()}><FileText />{t('Invoices')}</Link></Button>
                            <Button variant="outline" asChild><Link href={payments()}><Banknote />{t('Payments')}</Link></Button>
                            {can.reports && <Button variant="outline" asChild><Link href={reports()}>{t('Reports')}</Link></Button>}
                            {can.manage && <Button variant="outline" asChild><Link href={setup()}><Settings2 />{t('Fee setup')}</Link></Button>}
                        </>
                    }
                />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard label={t('Collected today')} value={formatMoney(stats.today)} icon={Wallet} tone="success" />
                    <StatCard label={t('Collected this month')} value={formatMoney(stats.month)} icon={Banknote} />
                    <StatCard label={t('Outstanding')} value={formatMoney(stats.outstanding)} icon={CalendarClock} tone="warning" />
                    <StatCard label={t('Overdue')} value={formatMoney(stats.overdue)} hint={t(':count invoices', { count: stats.overdue_count })} icon={AlertTriangle} tone="danger" />
                </div>

                <div className="grid gap-4 lg:grid-cols-5">
                    <section className="rounded-xl border border-border/70 bg-card p-4 lg:col-span-2">
                        <h2 className="mb-3 font-medium">{t('Collect a fee')}</h2>
                        <Form action={dashboard.url()} method="get" options={{ preserveState: true }} className="flex gap-2">
                            <Input name="q" defaultValue={q} placeholder={t('Student name or admission no.')} aria-label={t('Find a student')} autoFocus />
                            <Button type="submit" variant="outline" size="icon" aria-label={t('Search')}><Search /></Button>
                        </Form>
                        {q !== '' && (
                            <ul className="mt-3 divide-y divide-border/70 text-sm">
                                {matches.length === 0 && <li className="py-2 text-muted-foreground">{t('No students found')}</li>}
                                {matches.map((m) => (
                                    <li key={m.id}>
                                        <Link href={ledger(m.id)} className="flex items-center justify-between gap-2 py-2 hover:text-primary">
                                            <span><span className="font-medium">{m.full_name}</span>{m.name_bn && <span className="ml-1 text-muted-foreground">· {m.name_bn}</span>}</span>
                                            <span className="font-mono text-xs text-muted-foreground">{m.admission_no}</span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                        {stats.pending_online > 0 && <p className="mt-4 text-xs text-muted-foreground">{t(':count online payments are waiting for gateway confirmation.', { count: stats.pending_online })}</p>}
                    </section>

                    <section className="overflow-hidden rounded-xl border border-border/70 bg-card lg:col-span-3">
                        <h2 className="border-b border-border/70 px-4 py-3 font-medium">{t('Recent payments')}</h2>
                        <ul className="divide-y divide-border/70 text-sm">
                            {recent.length === 0 && <li className="px-4 py-6 text-center text-muted-foreground">{t('No payments yet')}</li>}
                            {recent.map((p) => (
                                <li key={p.id} className="flex items-center justify-between gap-3 px-4 py-2.5">
                                    <Link href={ledger(p.student_id)} className="min-w-0">
                                        <p className="truncate font-medium hover:text-primary">{p.student}</p>
                                        <p className="text-xs text-muted-foreground">{p.receipt_no} · {t('method_' + p.method)} · {p.received_by ?? p.gateway}</p>
                                    </Link>
                                    <div className="text-right">
                                        <p className="font-medium tabular-nums">{formatMoney(p.amount)}</p>
                                        <p className="text-xs text-muted-foreground">{formatDateTime(p.received_at)}</p>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </section>
                </div>
            </div>
        </>
    );
}

FeesDashboard.layout = { breadcrumbs: [{ title: 'Dashboard', href: home() }, { title: 'Fees', href: dashboard() }] };
