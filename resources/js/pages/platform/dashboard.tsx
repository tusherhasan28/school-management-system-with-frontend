import { Head, usePage } from '@inertiajs/react';
import { Clock, PauseCircle, School, Sparkles } from 'lucide-react';
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { StatCard } from '@/components/shared/stat-card';
import { formatDateTime, useT } from '@/lib/i18n';
import { dashboard } from '@/routes/platform';

type Props = {
    stats: { schools: number; active: number; suspended: number; pending: number };
    byStatus: { status: string; label: string; total: number }[];
    recentActivity: { id: number; action: string; school_id: number | null; created_at: string | null }[];
};

export default function PlatformDashboard({ stats, byStatus, recentActivity }: Props) {
    const t = useT();
    const { locale } = usePage().props;

    return (
        <>
            <Head title={t('Platform dashboard')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('Platform dashboard')}
                    description={t('Schools across the platform. Billing and usage arrive in Milestone 7.')}
                />
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard label={t('Schools')} value={stats.schools} icon={School} />
                    <StatCard label={t('Active or in trial')} value={stats.active} icon={Sparkles} tone="success" />
                    <StatCard label={t('Pending approval')} value={stats.pending} icon={Clock} tone="warning" />
                    <StatCard label={t('Suspended')} value={stats.suspended} icon={PauseCircle} tone="danger" />
                </div>

                <div className="grid gap-4 lg:grid-cols-5">
                    <section className="rounded-xl border border-border/70 bg-card p-5 lg:col-span-3">
                        <h2 className="mb-4 font-medium">{t('Schools by status')}</h2>
                        <div className="h-64">
                            <ResponsiveContainer width="100%" height="100%">
                                <BarChart data={byStatus} margin={{ left: -20 }}>
                                    <CartesianGrid vertical={false} strokeDasharray="3 3" className="stroke-border" />
                                    <XAxis dataKey="label" tickLine={false} axisLine={false} fontSize={12} />
                                    <YAxis allowDecimals={false} tickLine={false} axisLine={false} fontSize={12} />
                                    <Tooltip cursor={{ fill: 'var(--muted)' }} />
                                    <Bar dataKey="total" name={t('Schools')} fill="var(--primary)" radius={[6, 6, 0, 0]} />
                                </BarChart>
                            </ResponsiveContainer>
                        </div>
                    </section>
                    <section className="rounded-xl border border-border/70 bg-card lg:col-span-2">
                        <h2 className="border-b border-border/70 px-5 py-4 font-medium">{t('Recent platform activity')}</h2>
                        {recentActivity.length === 0 ? (
                            <div className="p-5">
                                <EmptyState title={t('No activity yet')} />
                            </div>
                        ) : (
                            <ul className="divide-y divide-border/70 text-sm">
                                {recentActivity.map((item) => (
                                    <li key={item.id} className="flex items-center justify-between gap-2 px-5 py-3">
                                        <code className="rounded bg-muted px-1.5 py-0.5 text-xs">{item.action}</code>
                                        <time className="text-muted-foreground">{formatDateTime(item.created_at, locale)}</time>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>
            </div>
        </>
    );
}

PlatformDashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
