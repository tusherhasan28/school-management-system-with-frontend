import { Head, Link, router } from '@inertiajs/react';
import { CreditCard } from 'lucide-react';
import type { Subscription } from '@/components/billing/shared';
import { SubscriptionBadge } from '@/components/billing/shared';
import type { Column } from '@/components/shared/data-table';
import { DataTable } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { Pagination } from '@/components/shared/pagination';
import { StatusBadge } from '@/components/shared/status-badge';
import { formatDateTime, formatMoney, useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes/platform';
import { show as showSchool } from '@/routes/platform/schools';
import { index } from '@/routes/platform/subscriptions';
import type { Paginated } from '@/types';

type Row = Subscription & { school: { id: number; name: string; slug: string } };
type Props = {
    subscriptions: Paginated<Row> | null;
    unmanaged: { id: number; name: string; slug: string; status: string }[];
    counts: Record<string, number>;
    filters: { status: string };
};

export default function SubscriptionsIndex({ subscriptions, unmanaged, counts, filters }: Props) {
    const t = useT();
    const tabs = ['', 'trialing', 'active', 'past_due', 'suspended', 'cancelled', 'expired', 'none'];
    const columns: Column<Row>[] = [
        { key: 'school', header: t('School'), cell: (r) => <Link href={showSchool(r.school.id)} className="font-medium hover:underline">{r.school.name}</Link> },
        { key: 'plan', header: t('Plan'), cell: (r) => <>{r.plan?.name} <span className="text-muted-foreground">· {t(r.billing_cycle)}</span></> },
        { key: 'status', header: t('Status'), cell: (r) => <SubscriptionBadge status={r.status} /> },
        { key: 'amount', header: t('Amount'), cell: (r) => <span className="tabular-nums">{formatMoney(r.amount, 'en')}</span>, hideBelow: 'md' },
        { key: 'period', header: t('Period ends'), cell: (r) => formatDateTime(r.current_period_end, 'en'), hideBelow: 'md' },
        { key: 'grace', header: t('Grace ends'), cell: (r) => (r.grace_ends_at ? formatDateTime(r.grace_ends_at, 'en') : '—'), hideBelow: 'lg' },
    ];

    return (
        <>
            <Head title={t('Subscriptions')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader title={t('Subscriptions')} description={t('Renewal invoices, grace periods and suspensions run every night (billing:run).')} />
                <nav className="flex flex-wrap gap-1" aria-label={t('Status')}>
                    {tabs.map((s) => (
                        <button
                            key={s || 'current'}
                            type="button"
                            onClick={() => router.get(index.url(), s ? { status: s } : {}, { preserveState: true })}
                            className={cn('rounded-full border px-3 py-1 text-sm', filters.status === s ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted')}
                        >
                            {s ? t(s === 'none' ? 'No subscription' : `subscription_${s}`) : t('Current')}
                            {s && <span className="ml-1.5 tabular-nums opacity-70">{counts[s] ?? 0}</span>}
                        </button>
                    ))}
                </nav>
                {filters.status === 'none' ? (
                    unmanaged.length === 0 ? <EmptyState icon={CreditCard} title={t('Every school has a subscription')} /> : (
                        <ul className="divide-y divide-border/70 rounded-xl border border-border/70 bg-card text-sm">
                            {unmanaged.map((s) => (
                                <li key={s.id} className="flex items-center justify-between px-4 py-3">
                                    <Link href={showSchool(s.id)} className="font-medium hover:underline">{s.name}</Link>
                                    <StatusBadge status={s.status} label={s.status} />
                                </li>
                            ))}
                        </ul>
                    )
                ) : subscriptions && subscriptions.data.length > 0 ? (
                    <>
                        <DataTable columns={columns} rows={subscriptions.data} rowKey={(r) => r.id} />
                        <Pagination page={subscriptions} />
                    </>
                ) : (
                    <EmptyState icon={CreditCard} title={t('No subscriptions')} />
                )}
                {filters.status === '' && unmanaged.length > 0 && (
                    <p className="text-sm text-muted-foreground">{t(':count schools have no subscription and are not limited.', { count: String(unmanaged.length) })}</p>
                )}
            </div>
        </>
    );
}

SubscriptionsIndex.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Subscriptions', href: index() }] };
