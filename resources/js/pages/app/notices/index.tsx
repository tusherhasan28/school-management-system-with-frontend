import { Head, Link, router, useForm } from '@inertiajs/react';
import { CalendarDays, Megaphone, Paperclip, Pencil, Pin, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import NoticeController from '@/actions/App/Modules/Notices/Http/Controllers/NoticeController';
import { BilingualField } from '@/components/shared/bilingual';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { EmptyState } from '@/components/shared/empty-state';
import { Field, FormSection, selectClass } from '@/components/shared/fields';
import { PageHeader } from '@/components/shared/page-header';
import { Pagination } from '@/components/shared/pagination';
import { StatusBadge } from '@/components/shared/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { formatDate, formatDateTime, useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { store as storeEvent, update as updateEvent } from '@/routes/events';
import { create, edit, index } from '@/routes/notices';
import type { Paginated } from '@/types';

type Notice = { id: number; title: string; title_bn: string | null; status: string; audiences: string[]; is_pinned: boolean; publish_at: string; expires_at: string | null; author: string | null; has_attachment: boolean };
type Event = { id: number; title: string; title_bn: string | null; description: string | null; description_bn: string | null; location: string | null; is_public: boolean; starts_on: string; ends_on: string | null; starts_at: string | null };

type Props = { notices: Paginated<Notice>; filters: { status: string; q: string }; events: Event[] };

const tone: Record<string, string> = { draft: 'archived', published: 'active', scheduled: 'trial', expired: 'cancelled' };

export default function Notices({ notices, filters, events }: Props) {
    const t = useT();
    const go = (patch: Record<string, string | null>) => router.get(index.url(), { ...filters, ...patch }, { preserveState: true, replace: true });

    return (
        <>
            <Head title={t('Notices')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader title={t('Notices and events')} description={t('Notices reach the website, staff, parents or students depending on who you address them to.')} actions={<Button asChild><Link href={create()}><Plus />{t('New notice')}</Link></Button>} />

                <div className="flex flex-wrap gap-2">
                    <Input className="w-56" defaultValue={filters.q} placeholder={t('Search')} onKeyDown={(e) => e.key === 'Enter' && go({ q: (e.target as HTMLInputElement).value })} aria-label={t('Search')} />
                    <select className={cn(selectClass, 'w-40')} value={filters.status} onChange={(e) => go({ status: e.target.value })} aria-label={t('Status')}>
                        <option value="">{t('All statuses')}</option>
                        {['published', 'scheduled', 'draft', 'expired'].map((s) => <option key={s} value={s}>{t('notice_' + s)}</option>)}
                    </select>
                </div>

                {notices.data.length === 0 ? <EmptyState icon={Megaphone} title={t('No notices yet')} /> : (
                    <ul className="divide-y divide-border/70 overflow-hidden rounded-xl border border-border/70 bg-card">
                        {notices.data.map((n) => (
                            <li key={n.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                <div className="min-w-0">
                                    <p className="flex items-center gap-1.5 font-medium">{n.is_pinned && <Pin className="size-3.5 text-amber-600" aria-label={t('Pinned')} />}{n.title}{n.has_attachment && <Paperclip className="size-3.5 text-muted-foreground" aria-label={t('Attachment')} />}</p>
                                    <p className="text-xs text-muted-foreground">{formatDateTime(n.publish_at)}{n.expires_at ? ` → ${formatDateTime(n.expires_at)}` : ''}{n.author ? ` · ${n.author}` : ''}</p>
                                    <div className="mt-1 flex flex-wrap gap-1">{n.audiences.map((a) => <Badge key={a} variant="secondary">{t('audience_' + a)}</Badge>)}</div>
                                </div>
                                <div className="flex items-center gap-1">
                                    <StatusBadge status={tone[n.status]} label={t('notice_' + n.status)} />
                                    <Button size="icon" variant="ghost" asChild><Link href={edit(n.id)} aria-label={t('Edit :name', { name: n.title })}><Pencil /></Link></Button>
                                    <ConfirmDialog trigger={<Button size="icon" variant="ghost" aria-label={t('Delete :name', { name: n.title })}><Trash2 className="text-destructive" /></Button>} title={t('Delete this notice?')} description={n.title} destructive onConfirm={() => router.delete(NoticeController.destroy.url(n.id), { preserveScroll: true })} />
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
                <Pagination page={notices} />

                <FormSection title={t('Events')} description={t('Public events appear on the website\'s events page and home page.')}>
                    <div className="mb-3"><EventDialog /></div>
                    <ul className="divide-y divide-border/70 text-sm">
                        {events.length === 0 && <li className="py-2 text-muted-foreground">{t('No events yet')}</li>}
                        {events.map((e) => (
                            <li key={e.id} className="flex items-center justify-between gap-2 py-2">
                                <span className="flex items-center gap-2"><CalendarDays className="size-4 text-muted-foreground" /><span><span className="font-medium">{e.title}</span><span className="block text-xs text-muted-foreground">{formatDate(e.starts_on)}{e.ends_on && e.ends_on !== e.starts_on ? ` → ${formatDate(e.ends_on)}` : ''}{e.location ? ` · ${e.location}` : ''}{!e.is_public ? ` · ${t('Not on website')}` : ''}</span></span></span>
                                <span className="flex">
                                    <EventDialog event={e} />
                                    <Button size="icon" variant="ghost" aria-label={t('Delete :name', { name: e.title })} onClick={() => router.delete(NoticeController.destroyEvent.url(e.id), { preserveScroll: true })}><Trash2 className="text-destructive" /></Button>
                                </span>
                            </li>
                        ))}
                    </ul>
                </FormSection>
            </div>
        </>
    );
}

function EventDialog({ event }: { event?: Event }) {
    const t = useT();
    const [open, setOpen] = useState(false);
    const form = useForm({
        title: event?.title ?? '', title_bn: event?.title_bn ?? '', description: event?.description ?? '', description_bn: event?.description_bn ?? '',
        starts_on: event?.starts_on ?? '', ends_on: event?.ends_on ?? '', starts_at: event?.starts_at ?? '', location: event?.location ?? '', is_public: event?.is_public ?? true,
    });
    const errors = form.errors as Record<string, string>;
    const save = () => (event ? form.put(updateEvent.url(event.id), { preserveScroll: true, onSuccess: () => setOpen(false) }) : form.post(storeEvent.url(), { preserveScroll: true, onSuccess: () => { setOpen(false); form.reset(); } }));

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{event ? <Button size="icon" variant="ghost" aria-label={t('Edit :name', { name: event.title })}><Pencil /></Button> : <Button size="sm" variant="outline"><Plus />{t('Add event')}</Button>}</DialogTrigger>
            <DialogContent className="sm:max-w-2xl">
                <DialogTitle>{event ? event.title : t('Add event')}</DialogTitle>
                <DialogDescription>{t('For example sports day, prize giving or a parents\' meeting.')}</DialogDescription>
                <div className="grid gap-4">
                    <BilingualField label={t('Title')} name="title" values={form.data} onChange={(f, v) => form.setData(f as 'title', v)} errors={errors} required />
                    <div className="grid gap-3 sm:grid-cols-4">
                        <Field label={t('Starts')} name="starts_on" required error={errors.starts_on}><Input id="starts_on" type="date" value={form.data.starts_on} onChange={(e) => form.setData('starts_on', e.target.value)} /></Field>
                        <Field label={t('Ends')} name="ends_on" error={errors.ends_on}><Input id="ends_on" type="date" value={form.data.ends_on} onChange={(e) => form.setData('ends_on', e.target.value)} /></Field>
                        <Field label={t('Time')} name="starts_at" error={errors.starts_at}><Input id="starts_at" type="time" value={form.data.starts_at} onChange={(e) => form.setData('starts_at', e.target.value)} /></Field>
                        <Field label={t('Place')} name="location"><Input id="location" value={form.data.location} onChange={(e) => form.setData('location', e.target.value)} /></Field>
                    </div>
                    <BilingualField label={t('Description')} name="description" values={form.data} onChange={(f, v) => form.setData(f as 'description', v)} errors={errors} multiline rows={3} markdown />
                    <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="size-4 accent-primary" checked={form.data.is_public} onChange={(e) => form.setData('is_public', e.target.checked)} />{t('Show on the website')}</label>
                    <Button onClick={save} disabled={form.processing}>{form.processing && <Spinner />}{t('Save')}</Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}

Notices.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Notices', href: index() }] };
