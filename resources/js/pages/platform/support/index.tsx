import { Head, Link, router } from '@inertiajs/react';
import { LifeBuoy } from 'lucide-react';
import type { Column } from '@/components/shared/data-table';
import { DataTable } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { Pagination } from '@/components/shared/pagination';
import { StatusBadge } from '@/components/shared/status-badge';
import { TicketStatus } from '@/components/support/thread';
import { formatDateTime, useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes/platform';
import { index, show } from '@/routes/platform/support';
import { snapshot } from '@/routes/platform/support/access';
import type { Paginated } from '@/types';

type Ticket = { id: number; reference: string; subject: string; category: string; priority: string; status: string; school: string | null; assignee: string | null; last_activity_at: string | null };
export type Grant = { id: number; school: string | null; school_id: number; reason: string; duration_hours: number; status: string; expires_at: string | null; created_at: string | null; view_count: number };
type Props = { tickets: Paginated<Ticket>; grants: Grant[]; filters: { status: string; mine: boolean } };

export const grantTone: Record<string, string> = { requested: 'pending', approved: 'active', rejected: 'cancelled', revoked: 'cancelled', expired: 'archived' };

export default function PlatformSupportIndex({ tickets, grants, filters }: Props) {
    const t = useT();
    const apply = (next: Partial<Props['filters']>) => {
        const q = { ...filters, ...next };
        router.get(index.url(), { status: q.status, ...(q.mine ? { mine: 1 } : {}) }, { preserveState: true });
    };
    const columns: Column<Ticket>[] = [
        { key: 'subject', header: t('Ticket'), cell: (r) => <><Link href={show(r.id)} className="font-medium hover:underline">{r.subject}</Link><p className="text-xs text-muted-foreground">{r.reference} · {r.school}</p></> },
        { key: 'priority', header: t('Priority'), cell: (r) => <span className={cn(r.priority === 'urgent' && 'font-semibold text-rose-700', r.priority === 'high' && 'text-amber-700')}>{t(`priority_${r.priority}`)}</span> },
        { key: 'status', header: t('Status'), cell: (r) => <TicketStatus status={r.status} /> },
        { key: 'assignee', header: t('Assigned to'), cell: (r) => r.assignee ?? '—', hideBelow: 'md' },
        { key: 'activity', header: t('Last activity'), cell: (r) => formatDateTime(r.last_activity_at, 'en'), hideBelow: 'lg' },
    ];

    return (
        <>
            <Head title={t('Support')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader title={t('Support')} description={t('Tickets from schools. Access to a school\'s data needs the school\'s approval and expires on its own.')} />
                <div className="flex flex-wrap items-center gap-1">
                    {['active', 'open', 'in_progress', 'waiting', 'resolved', 'closed'].map((s) => (
                        <button key={s} type="button" onClick={() => apply({ status: s })} className={cn('rounded-full border px-3 py-1 text-sm', filters.status === s ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted')}>
                            {s === 'active' ? t('Needs attention') : t(`ticket_${s}`)}
                        </button>
                    ))}
                    <label className="ml-2 flex items-center gap-1.5 text-sm"><input type="checkbox" checked={filters.mine} onChange={(e) => apply({ mine: e.target.checked })} />{t('Assigned to me')}</label>
                </div>
                {tickets.data.length === 0 ? <EmptyState icon={LifeBuoy} title={t('No tickets')} /> : <DataTable columns={columns} rows={tickets.data} rowKey={(r) => r.id} />}
                <Pagination page={tickets} />

                {grants.length > 0 && (
                    <section className="rounded-xl border border-border/70 bg-card">
                        <h2 className="border-b border-border/70 px-5 py-3 font-medium">{t('My access requests')}</h2>
                        <ul className="divide-y divide-border/70 text-sm">
                            {grants.map((g) => (
                                <li key={g.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                                    <div><p className="font-medium">{g.school}</p><p className="text-muted-foreground">{g.reason}</p></div>
                                    <div className="flex items-center gap-2">
                                        <StatusBadge status={grantTone[g.status] ?? 'archived'} label={t(`grant_${g.status}`)} />
                                        {g.status === 'approved' && <Link className="text-primary hover:underline" href={snapshot(g.id)}>{t('Open snapshot')}</Link>}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </>
    );
}

PlatformSupportIndex.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Support', href: index() }] };
