import { Head, router } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { PageHeader } from '@/components/shared/page-header';
import { StatCard } from '@/components/shared/stat-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate, formatMoney, useT } from '@/lib/i18n';
import { dashboard as home } from '@/routes';
import { dashboard, reports } from '@/routes/fees';
import { exportMethod } from '@/routes/fees/reports';

type Row = { name: string; count: number; amount: string };
type DuesRow = { class: string; students: number; total: string; not_due: string; d1_30: string; d31_60: string; d61_90: string; d90_plus: string };

type Props = {
    filters: { from: string; to: string };
    collections: { gross: string; reversed: string; net: string; count: number; by_method: (Row & { method: string })[]; by_collector: Row[]; by_day: { day: string; amount: string }[] };
    dues: { rows: DuesRow[]; totals: Omit<DuesRow, 'class' | 'students'> };
};

const buckets = ['not_due', 'd1_30', 'd31_60', 'd61_90', 'd90_plus'] as const;

export default function FinanceReports({ filters, collections: c, dues }: Props) {
    const t = useT();
    const go = (patch: Partial<Props['filters']>) => router.get(reports.url(), { ...filters, ...patch }, { preserveState: true, preserveScroll: true, replace: true });
    const max = Math.max(1, ...c.by_day.map((d) => Number(d.amount)));

    return (
        <>
            <Head title={t('Fee reports')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('Fee reports')}
                    description={t('Money received in a period, and what is still owed by class.')}
                    actions={
                        <>
                            <Input type="date" className="w-40" value={filters.from} onChange={(e) => go({ from: e.target.value })} aria-label={t('From')} />
                            <Input type="date" className="w-40" value={filters.to} onChange={(e) => go({ to: e.target.value })} aria-label={t('To')} />
                        </>
                    }
                />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard label={t('Received')} value={formatMoney(c.gross)} hint={t(':count payments', { count: c.count })} />
                    <StatCard label={t('Reversed or refunded')} value={formatMoney(c.reversed)} tone="warning" />
                    <StatCard label={t('Net collection')} value={formatMoney(c.net)} tone="success" />
                    <StatCard label={t('Outstanding')} value={formatMoney(dues.totals.total)} tone="danger" />
                </div>

                <section className="rounded-xl border border-border/70 bg-card p-4">
                    <header className="mb-3 flex items-center justify-between gap-2">
                        <h2 className="font-medium">{t('Collections by day')}</h2>
                        <Button size="sm" variant="outline" asChild><a href={exportMethod.url('collections', { query: filters })}><Download />{t('Download XLSX')}</a></Button>
                    </header>
                    {c.by_day.length === 0 ? <p className="py-6 text-center text-sm text-muted-foreground">{t('No payments in this period')}</p> : (
                        <div className="flex h-40 items-end gap-1 overflow-x-auto" role="img" aria-label={c.by_day.map((d) => `${d.day}: ${d.amount}`).join(', ')}>
                            {c.by_day.map((d) => (
                                <div key={d.day} className="group flex min-w-6 flex-1 flex-col items-center justify-end gap-1" title={`${formatDate(d.day)}: ${formatMoney(d.amount)}`}>
                                    <div className="w-full rounded-t bg-primary/70 group-hover:bg-primary" style={{ height: `${(Number(d.amount) / max) * 130}px` }} />
                                    <span className="text-[10px] text-muted-foreground tabular-nums">{d.day.slice(8)}/{d.day.slice(5, 7)}</span>
                                </div>
                            ))}
                        </div>
                    )}
                    <div className="mt-4 grid gap-4 md:grid-cols-2">
                        <table className="w-full text-sm">
                            <thead className="text-left text-xs text-muted-foreground uppercase"><tr><th className="py-1">{t('Method')}</th><th className="py-1 text-right">{t('Payments')}</th><th className="py-1 text-right">{t('Amount')}</th></tr></thead>
                            <tbody className="divide-y divide-border/70">{c.by_method.map((r) => <tr key={r.method}><td className="py-1.5">{t('method_' + r.method)}</td><td className="py-1.5 text-right tabular-nums">{r.count}</td><td className="py-1.5 text-right tabular-nums">{formatMoney(r.amount)}</td></tr>)}</tbody>
                        </table>
                        <table className="w-full text-sm">
                            <thead className="text-left text-xs text-muted-foreground uppercase"><tr><th className="py-1">{t('Received by')}</th><th className="py-1 text-right">{t('Payments')}</th><th className="py-1 text-right">{t('Amount')}</th></tr></thead>
                            <tbody className="divide-y divide-border/70">{c.by_collector.map((r) => <tr key={r.name}><td className="py-1.5">{r.name}</td><td className="py-1.5 text-right tabular-nums">{r.count}</td><td className="py-1.5 text-right tabular-nums">{formatMoney(r.amount)}</td></tr>)}</tbody>
                        </table>
                    </div>
                </section>

                <section className="overflow-hidden rounded-xl border border-border/70 bg-card">
                    <header className="flex items-center justify-between gap-2 border-b border-border/70 px-4 py-3">
                        <div>
                            <h2 className="font-medium">{t('Dues by class')}</h2>
                            <p className="text-sm text-muted-foreground">{t('Open balances today, by days past the due date.')}</p>
                        </div>
                        <Button size="sm" variant="outline" asChild><a href={exportMethod.url('dues', { query: filters })}><Download />{t('Download XLSX')}</a></Button>
                    </header>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left text-xs text-muted-foreground uppercase">
                                <tr><th className="px-4 py-2">{t('Class')}</th><th className="px-4 py-2 text-right">{t('Students')}</th>{buckets.map((b) => <th key={b} className="px-4 py-2 text-right whitespace-nowrap">{t('aging_' + b)}</th>)}<th className="px-4 py-2 text-right">{t('Total')}</th></tr>
                            </thead>
                            <tbody className="divide-y divide-border/70">
                                {dues.rows.length === 0 && <tr><td colSpan={8} className="px-4 py-6 text-center text-muted-foreground">{t('Nothing is owed.')}</td></tr>}
                                {dues.rows.map((r) => (
                                    <tr key={r.class}>
                                        <td className="px-4 py-2 font-medium">{r.class}</td>
                                        <td className="px-4 py-2 text-right tabular-nums">{r.students}</td>
                                        {buckets.map((b) => <td key={b} className={`px-4 py-2 text-right tabular-nums ${b !== 'not_due' && Number(r[b]) > 0 ? 'text-destructive' : ''}`}>{Number(r[b]) ? formatMoney(r[b]) : '—'}</td>)}
                                        <td className="px-4 py-2 text-right font-medium tabular-nums">{formatMoney(r.total)}</td>
                                    </tr>
                                ))}
                            </tbody>
                            {dues.rows.length > 0 && (
                                <tfoot className="border-t-2 border-border font-medium">
                                    <tr><td className="px-4 py-2">{t('Total')}</td><td />{buckets.map((b) => <td key={b} className="px-4 py-2 text-right tabular-nums">{formatMoney(dues.totals[b])}</td>)}<td className="px-4 py-2 text-right tabular-nums">{formatMoney(dues.totals.total)}</td></tr>
                                </tfoot>
                            )}
                        </table>
                    </div>
                </section>
            </div>
        </>
    );
}

FinanceReports.layout = { breadcrumbs: [{ title: 'Dashboard', href: home() }, { title: 'Fees', href: dashboard() }] };
