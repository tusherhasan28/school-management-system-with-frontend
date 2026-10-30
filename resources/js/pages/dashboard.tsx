import { Head, Link, usePage } from '@inertiajs/react';
import { ClipboardList, GraduationCap, ListChecks, UserRound } from 'lucide-react';
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { StatCard } from '@/components/shared/stat-card';
import { Button } from '@/components/ui/button';
import { formatDateTime, useT } from '@/lib/i18n';
import { dashboard } from '@/routes';
import { show as setupShow } from '@/routes/setup';

type Props = {
    session: string | null;
    stats: { students: number | null; staff: number | null; pendingAdmissions: number | null };
    byClass: { name: string; total: number }[];
    setup: { completed: string[]; finished_at: string | null } | null;
    recentActivity: { id: number; action: string; user: string | null; created_at: string | null }[];
};

export default function Dashboard({ session, stats, byClass, setup, recentActivity }: Props) {
    const t = useT();
    const { auth, locale, school } = usePage().props;
    const nf = new Intl.NumberFormat(locale === 'bn' ? 'bn-BD' : 'en-BD');

    return (
        <>
            <Head title={t('Dashboard')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('Welcome, :name', { name: auth.user.name })}
                    description={session ? t('Academic session :name', { name: session }) : t('No academic session yet.')}
                />

                {setup && (
                    <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-primary/30 bg-primary/5 p-4">
                        <div className="flex items-center gap-3">
                            <ListChecks className="size-5 text-primary" />
                            <p className="text-sm">{t('Finish setting up your school: :done of 4 steps done.', { done: setup.completed.length })}</p>
                        </div>
                        <Button asChild size="sm"><Link href={setupShow()}>{t('Continue setup')}</Link></Button>
                    </div>
                )}

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {stats.students !== null && <StatCard label={t('Active students')} value={nf.format(stats.students)} icon={GraduationCap} />}
                    {stats.staff !== null && <StatCard label={t('Teachers & staff')} value={nf.format(stats.staff)} icon={UserRound} tone="success" />}
                    {stats.pendingAdmissions !== null && <StatCard label={t('Applications to review')} value={nf.format(stats.pendingAdmissions)} icon={ClipboardList} tone="warning" />}
                </div>

                <div className="grid gap-4 lg:grid-cols-5">
                    {byClass.length > 0 && (
                        <section className="rounded-xl border border-border/70 bg-card p-5 lg:col-span-3">
                            <h2 className="mb-4 font-medium">{t('Students by class')}</h2>
                            <div className="h-64">
                                <ResponsiveContainer width="100%" height="100%">
                                    <BarChart data={byClass} margin={{ left: -20 }}>
                                        <CartesianGrid vertical={false} strokeDasharray="3 3" className="stroke-border" />
                                        <XAxis dataKey="name" tickLine={false} axisLine={false} fontSize={12} />
                                        <YAxis allowDecimals={false} tickLine={false} axisLine={false} fontSize={12} />
                                        <Tooltip cursor={{ fill: 'var(--muted)' }} />
                                        <Bar dataKey="total" name={t('Students')} fill="var(--primary)" radius={[6, 6, 0, 0]} />
                                    </BarChart>
                                </ResponsiveContainer>
                            </div>
                        </section>
                    )}

                    {auth.permissions.includes('audit.view') && (
                        <section className="rounded-xl border border-border/70 bg-card lg:col-span-2">
                            <h2 className="border-b border-border/70 px-5 py-4 font-medium">{t('Recent activity')}</h2>
                            {recentActivity.length === 0 ? (
                                <div className="p-5"><EmptyState title={t('No activity yet')} description={t('Changes made by your staff will appear here.')} /></div>
                            ) : (
                                <ul className="divide-y divide-border/70">
                                    {recentActivity.map((item) => (
                                        <li key={item.id} className="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm">
                                            <span>
                                                <span className="font-medium">{item.user ?? t('System')}</span>{' '}
                                                <code className="rounded bg-muted px-1.5 py-0.5 text-xs">{item.action}</code>
                                            </span>
                                            <time className="text-muted-foreground">{formatDateTime(item.created_at, locale, school?.timezone)}</time>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>
                    )}
                </div>
            </div>
        </>
    );
}

Dashboard.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }] };
