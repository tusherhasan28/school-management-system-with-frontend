import { Head } from '@inertiajs/react';
import { ShieldAlert } from 'lucide-react';
import { PageHeader } from '@/components/shared/page-header';
import { formatDateTime, useT } from '@/lib/i18n';
import { dashboard } from '@/routes/platform';
import { index } from '@/routes/platform/support';
import type { Grant } from './index';

type Props = {
    grant: Grant;
    users: { id: number; name: string; email: string | null; status: string; roles: string[]; last_login_at: string | null; two_factor: boolean }[];
    activity: { id: number; action: string; user_id: number | null; subject: string | null; created_at: string | null }[];
    failed_messages: { id: number; channel: string; recipient: string; template: string | null; error: string | null; attempts: number; created_at: string | null }[];
    counts: Record<string, number>;
};

/** Read-only support snapshot, available only during a school-approved grant. */
export default function SupportSnapshot({ grant, users, activity, failed_messages, counts }: Props) {
    const t = useT();

    return (
        <>
            <Head title={t('Support snapshot')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader title={`${t('Support snapshot')}: ${grant.school}`} description={grant.reason} />
                <p className="flex items-center gap-2 rounded-lg border border-amber-500/40 bg-amber-500/10 px-4 py-2 text-sm">
                    <ShieldAlert className="size-4" />{t('Read-only. Access ends :time. This view has been logged in the school\'s and the platform\'s audit trail.', { time: formatDateTime(grant.expires_at, 'en') })}
                </p>
                <div className="flex flex-wrap gap-2 text-sm">
                    {Object.entries(counts).map(([k, v]) => <span key={k} className="rounded-lg border px-3 py-1.5">{t(`feature_${k}`)}: <b className="tabular-nums">{v}</b></span>)}
                </div>
                <section className="overflow-x-auto rounded-xl border border-border/70 bg-card">
                    <h2 className="border-b px-5 py-3 font-medium">{t('Users')}</h2>
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left text-xs text-muted-foreground uppercase"><tr><th className="px-4 py-2">{t('Name')}</th><th className="px-4 py-2">{t('Roles')}</th><th className="px-4 py-2">{t('Status')}</th><th className="px-4 py-2">{t('Last login')}</th></tr></thead>
                        <tbody className="divide-y">
                            {users.map((u) => (
                                <tr key={u.id}><td className="px-4 py-2">{u.name}<div className="text-xs text-muted-foreground">{u.email}</div></td><td className="px-4 py-2">{u.roles.join(', ')}</td><td className="px-4 py-2">{u.status}{u.two_factor ? ' · 2FA' : ''}</td><td className="px-4 py-2">{formatDateTime(u.last_login_at, 'en')}</td></tr>
                            ))}
                        </tbody>
                    </table>
                </section>
                <div className="grid gap-4 lg:grid-cols-2">
                    <section className="rounded-xl border border-border/70 bg-card">
                        <h2 className="border-b px-5 py-3 font-medium">{t('Recent activity')}</h2>
                        <ul className="divide-y text-sm">{activity.map((a) => <li key={a.id} className="flex justify-between gap-2 px-5 py-2"><code className="text-xs">{a.action}</code><span className="text-muted-foreground">{a.subject} · {formatDateTime(a.created_at, 'en')}</span></li>)}</ul>
                    </section>
                    <section className="rounded-xl border border-border/70 bg-card">
                        <h2 className="border-b px-5 py-3 font-medium">{t('Failed messages')}</h2>
                        {failed_messages.length === 0 ? <p className="px-5 py-3 text-sm text-muted-foreground">{t('None')}</p> : (
                            <ul className="divide-y text-sm">{failed_messages.map((m) => <li key={m.id} className="px-5 py-2"><p>{m.channel} · {m.recipient} · {m.template}</p><p className="text-xs text-rose-700">{m.error} ({m.attempts}×)</p></li>)}</ul>
                        )}
                    </section>
                </div>
            </div>
        </>
    );
}

SupportSnapshot.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Support', href: index() }] };
