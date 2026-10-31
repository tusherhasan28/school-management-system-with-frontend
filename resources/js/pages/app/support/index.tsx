import { Head, Link, router, useForm } from '@inertiajs/react';
import { LifeBuoy, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { EmptyState } from '@/components/shared/empty-state';
import { selectClass } from '@/components/shared/fields';
import { PageHeader } from '@/components/shared/page-header';
import { StatusBadge } from '@/components/shared/status-badge';
import { TicketStatus } from '@/components/support/thread';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime, useT } from '@/lib/i18n';
import { dashboard } from '@/routes';
import { index, show, store } from '@/routes/support';
import { decide, revoke } from '@/routes/support/access';

type Props = {
    tickets: { id: number; reference: string; subject: string; category: string; priority: string; status: string; last_activity_at: string | null }[];
    grants: { id: number; agent: string | null; reason: string; duration_hours: number; status: string; expires_at: string | null; created_at: string | null; view_count: number }[];
};

const grantTone: Record<string, string> = { requested: 'pending', approved: 'active', rejected: 'cancelled', revoked: 'cancelled', expired: 'archived' };

export default function SupportIndex({ tickets, grants }: Props) {
    const t = useT();
    const [creating, setCreating] = useState(tickets.length === 0);
    const form = useForm({ subject: '', category: 'technical', priority: 'normal', message: '' });

    return (
        <>
            <Head title={t('Support')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader title={t('Support')} description={t('Ask the platform team for help. Replies also appear in your notifications.')}
                    actions={!creating && <Button onClick={() => setCreating(true)}>{t('New ticket')}</Button>} />

                {grants.length > 0 && (
                    <section className="rounded-xl border border-border/70 bg-card">
                        <header className="flex items-center gap-2 border-b border-border/70 px-5 py-3"><ShieldCheck className="size-4 text-muted-foreground" /><h2 className="font-medium">{t('Support access requests')}</h2></header>
                        <p className="px-5 pt-3 text-sm text-muted-foreground">{t('Approving lets that support person see your users, recent activity and failed messages — read-only, for the hours shown. Every view is logged. You can revoke it at any time.')}</p>
                        <ul className="divide-y divide-border/70">
                            {grants.map((g) => (
                                <li key={g.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm">
                                    <div className="min-w-0">
                                        <p className="font-medium">{g.agent} · {t(':hours hours', { hours: String(g.duration_hours) })}</p>
                                        <p className="text-muted-foreground">{g.reason}</p>
                                        <p className="text-xs text-muted-foreground">
                                            {formatDateTime(g.created_at)}{g.expires_at ? ` · ${t('until')} ${formatDateTime(g.expires_at)}` : ''}{g.view_count ? ` · ${t('viewed :count times', { count: String(g.view_count) })}` : ''}
                                        </p>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <StatusBadge status={grantTone[g.status] ?? 'archived'} label={t(`grant_${g.status}`)} />
                                        {g.status === 'requested' && (
                                            <>
                                                <Button size="sm" onClick={() => router.post(decide.url(g.id), { approve: true }, { preserveScroll: true })}>{t('Approve')}</Button>
                                                <Button size="sm" variant="outline" onClick={() => router.post(decide.url(g.id), { approve: false }, { preserveScroll: true })}>{t('Reject')}</Button>
                                            </>
                                        )}
                                        {g.status === 'approved' && <Button size="sm" variant="outline" onClick={() => router.post(revoke.url(g.id), {}, { preserveScroll: true })}>{t('Revoke')}</Button>}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {creating && (
                    <form className="grid max-w-3xl gap-4 rounded-xl border border-border/70 bg-card p-5" onSubmit={(e) => { e.preventDefault(); form.post(store.url()); }}>
                        <h2 className="font-medium">{t('New ticket')}</h2>
                        <div className="grid gap-1.5"><Label htmlFor="subject">{t('Subject')}</Label><Input id="subject" value={form.data.subject} onChange={(e) => form.setData('subject', e.target.value)} /><InputError message={form.errors.subject} /></div>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="grid gap-1.5"><Label htmlFor="category">{t('Topic')}</Label>
                                <select id="category" className={selectClass} value={form.data.category} onChange={(e) => form.setData('category', e.target.value)}>
                                    {['technical', 'billing', 'data', 'feature', 'other'].map((c) => <option key={c} value={c}>{t(`ticket_category_${c}`)}</option>)}
                                </select>
                            </div>
                            <div className="grid gap-1.5"><Label htmlFor="priority">{t('Priority')}</Label>
                                <select id="priority" className={selectClass} value={form.data.priority} onChange={(e) => form.setData('priority', e.target.value)}>
                                    {['low', 'normal', 'high', 'urgent'].map((p) => <option key={p} value={p}>{t(`priority_${p}`)}</option>)}
                                </select>
                            </div>
                        </div>
                        <div className="grid gap-1.5"><Label htmlFor="message">{t('Describe the problem')}</Label>
                            <textarea id="message" className="min-h-32 rounded-md border border-input bg-transparent px-3 py-2 text-sm" value={form.data.message} onChange={(e) => form.setData('message', e.target.value)} />
                            <p className="text-xs text-muted-foreground">{t('Do not write passwords, NID or birth registration numbers in a ticket.')}</p>
                            <InputError message={form.errors.message} />
                        </div>
                        <div className="flex gap-2">
                            <Button type="submit" disabled={form.processing}>{form.processing && <Spinner />}{t('Send')}</Button>
                            {tickets.length > 0 && <Button type="button" variant="ghost" onClick={() => setCreating(false)}>{t('Cancel')}</Button>}
                        </div>
                    </form>
                )}

                {tickets.length === 0 ? !creating && <EmptyState icon={LifeBuoy} title={t('No tickets yet')} /> : (
                    <ul className="divide-y divide-border/70 rounded-xl border border-border/70 bg-card">
                        {tickets.map((tk) => (
                            <li key={tk.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm">
                                <div className="min-w-0">
                                    <Link href={show(tk.id)} className="font-medium hover:underline">{tk.subject}</Link>
                                    <p className="text-muted-foreground">{tk.reference} · {t(`ticket_category_${tk.category}`)} · {formatDateTime(tk.last_activity_at)}</p>
                                </div>
                                <TicketStatus status={tk.status} />
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

SupportIndex.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Support', href: index() }] };
