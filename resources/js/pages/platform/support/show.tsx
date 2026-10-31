import { Head, Link, router, useForm } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { selectClass } from '@/components/shared/fields';
import { PageHeader } from '@/components/shared/page-header';
import { StatusBadge } from '@/components/shared/status-badge';
import type { TicketMessage } from '@/components/support/thread';
import { Thread, TicketStatus } from '@/components/support/thread';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime, useT } from '@/lib/i18n';
import { dashboard } from '@/routes/platform';
import { show as showSchool } from '@/routes/platform/schools';
import { index, reply, status as setStatus } from '@/routes/platform/support';
import { request as requestAccess, snapshot } from '@/routes/platform/support/access';
import type { Grant } from './index';
import { grantTone } from './index';

type Props = {
    ticket: { id: number; reference: string; subject: string; category: string; priority: string; status: string; school: { id: number; name: string; slug: string }; assignee: string | null; created_at: string | null };
    messages: TicketMessage[];
    grants: Grant[];
    maxHours: number;
    can: { manage: boolean };
};

export default function PlatformTicket({ ticket, messages, grants, maxHours, can }: Props) {
    const t = useT();
    const form = useForm({ message: '', internal: false });
    const access = useForm({ reason: '', duration_hours: '2', support_ticket_id: ticket.id });

    return (
        <>
            <Head title={ticket.reference} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader title={ticket.subject} description={`${ticket.reference} · ${t(`ticket_category_${ticket.category}`)} · ${t(`priority_${ticket.priority}`)}`} actions={<TicketStatus status={ticket.status} />} />
                <div className="grid gap-6 lg:grid-cols-3">
                    <div className="grid content-start gap-4 lg:col-span-2">
                        <Thread messages={messages} mine="platform" locale="en" />
                        {can.manage && (
                            <form className="grid gap-2" onSubmit={(e) => { e.preventDefault(); form.post(reply.url(ticket.id), { preserveScroll: true, onSuccess: () => form.reset() }); }}>
                                <Label htmlFor="reply">{t('Reply')}</Label>
                                <textarea id="reply" className="min-h-24 rounded-md border border-input bg-transparent px-3 py-2 text-sm" value={form.data.message} onChange={(e) => form.setData('message', e.target.value)} />
                                <InputError message={form.errors.message} />
                                <div className="flex items-center gap-3">
                                    <Button type="submit" disabled={form.processing}>{form.processing && <Spinner />}{form.data.internal ? t('Add internal note') : t('Send to school')}</Button>
                                    <label className="flex items-center gap-1.5 text-sm"><input type="checkbox" checked={form.data.internal} onChange={(e) => form.setData('internal', e.target.checked)} />{t('Internal note (not shown to the school)')}</label>
                                </div>
                            </form>
                        )}
                    </div>
                    <aside className="grid content-start gap-4">
                        <section className="rounded-xl border border-border/70 bg-card p-4 text-sm">
                            <p className="text-muted-foreground">{t('School')}</p>
                            <Link href={showSchool(ticket.school.id)} className="font-medium hover:underline">{ticket.school.name}</Link>
                            <p className="mt-2 text-muted-foreground">{t('Assigned to')}: {ticket.assignee ?? '—'}</p>
                            {can.manage && (
                                <div className="mt-3 flex gap-2">
                                    <select aria-label={t('Status')} className={selectClass} value={ticket.status} onChange={(e) => router.post(setStatus.url(ticket.id), { status: e.target.value }, { preserveScroll: true })}>
                                        {['open', 'in_progress', 'waiting', 'resolved', 'closed'].map((s) => <option key={s} value={s}>{t(`ticket_${s}`)}</option>)}
                                    </select>
                                    <Button size="sm" variant="outline" onClick={() => router.post(setStatus.url(ticket.id), { status: 'in_progress', assign_to_me: true }, { preserveScroll: true })}>{t('Take it')}</Button>
                                </div>
                            )}
                        </section>
                        {can.manage && (
                            <form className="grid gap-3 rounded-xl border border-border/70 bg-card p-4" onSubmit={(e) => { e.preventDefault(); access.post(requestAccess.url(ticket.school.id), { preserveScroll: true, onSuccess: () => access.reset('reason') }); }}>
                                <h2 className="font-medium">{t('Ask for support access')}</h2>
                                <p className="text-xs text-muted-foreground">{t('The school must approve. You then see a read-only snapshot (users, recent activity, failed messages) until the time runs out. Every view is logged on both sides.')}</p>
                                <div className="grid gap-1.5"><Label htmlFor="reason">{t('Reason')}</Label><Input id="reason" value={access.data.reason} onChange={(e) => access.setData('reason', e.target.value)} /><InputError message={access.errors.reason} /></div>
                                <div className="grid gap-1.5"><Label htmlFor="hours">{t('Hours (max :max)', { max: String(maxHours) })}</Label><Input id="hours" inputMode="numeric" value={access.data.duration_hours} onChange={(e) => access.setData('duration_hours', e.target.value)} /><InputError message={access.errors.duration_hours} /></div>
                                <Button type="submit" variant="outline" disabled={access.processing}>{t('Request access')}</Button>
                                {grants.length > 0 && (
                                    <ul className="space-y-1 border-t pt-2 text-xs">
                                        {grants.map((g) => (
                                            <li key={g.id} className="flex items-center justify-between gap-2">
                                                <span>{formatDateTime(g.created_at, 'en')}</span>
                                                <span className="flex items-center gap-2"><StatusBadge status={grantTone[g.status] ?? 'archived'} label={t(`grant_${g.status}`)} />{g.status === 'approved' && <Link className="text-primary hover:underline" href={snapshot(g.id)}>{t('Open')}</Link>}</span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </form>
                        )}
                    </aside>
                </div>
            </div>
        </>
    );
}

PlatformTicket.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Support', href: index() }] };
