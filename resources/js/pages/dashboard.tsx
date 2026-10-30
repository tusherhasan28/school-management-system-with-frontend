import { Head, usePage } from '@inertiajs/react';
import { Activity, Building2, Users } from 'lucide-react';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { StatCard } from '@/components/shared/stat-card';
import { formatDateTime, useT } from '@/lib/i18n';
import { dashboard } from '@/routes';

type Props = {
    stats: { campuses: number; users: number };
    recentActivity: {
        id: number;
        action: string;
        user: string | null;
        created_at: string | null;
    }[];
};

export default function Dashboard({ stats, recentActivity }: Props) {
    const t = useT();
    const { auth, locale, school } = usePage().props;
    const nf = new Intl.NumberFormat(locale === 'bn' ? 'bn-BD' : 'en-BD');

    return (
        <>
            <Head title={t('Dashboard')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={t('Welcome, :name', { name: auth.user.name })}
                    description={t(
                        'Overview of your school. More modules (students, attendance, exams, fees) arrive in upcoming milestones.',
                    )}
                />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <StatCard
                        label={t('Campuses')}
                        value={nf.format(stats.campuses)}
                        icon={Building2}
                    />
                    <StatCard
                        label={t('User accounts')}
                        value={nf.format(stats.users)}
                        icon={Users}
                        tone="success"
                    />
                    <StatCard
                        label={t('Timezone')}
                        value={school?.timezone ?? 'Asia/Dhaka'}
                        icon={Activity}
                        tone="warning"
                    />
                </div>

                {auth.permissions.includes('audit.view') && (
                    <section className="rounded-xl border border-border/70 bg-card">
                        <header className="border-b border-border/70 px-5 py-4">
                            <h2 className="font-medium">{t('Recent activity')}</h2>
                        </header>
                        {recentActivity.length === 0 ? (
                            <div className="p-5">
                                <EmptyState
                                    title={t('No activity yet')}
                                    description={t('Changes made by your staff will appear here.')}
                                />
                            </div>
                        ) : (
                            <ul className="divide-y divide-border/70">
                                {recentActivity.map((item) => (
                                    <li
                                        key={item.id}
                                        className="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm"
                                    >
                                        <span>
                                            <span className="font-medium">
                                                {item.user ?? t('System')}
                                            </span>{' '}
                                            <code className="rounded bg-muted px-1.5 py-0.5 text-xs">
                                                {item.action}
                                            </code>
                                        </span>
                                        <time className="text-muted-foreground">
                                            {formatDateTime(
                                                item.created_at,
                                                locale,
                                                school?.timezone,
                                            )}
                                        </time>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                )}
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
