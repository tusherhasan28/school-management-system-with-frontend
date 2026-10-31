import { Head, Link, router, useForm } from '@inertiajs/react';
import { MessageSquare, RotateCw, Settings } from 'lucide-react';
import InputError from '@/components/input-error';
import type { Column } from '@/components/shared/data-table';
import { DataTable } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { selectClass } from '@/components/shared/fields';
import { PageHeader } from '@/components/shared/page-header';
import { Pagination } from '@/components/shared/pagination';
import { StatCard } from '@/components/shared/stat-card';
import { StatusBadge } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime, useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index, retry, send, settings } from '@/routes/messages';
import type { Paginated } from '@/types';

type Log = { id: number; channel: 'sms' | 'mail'; recipient: string; name: string | null; template: string | null; body: string; segments: number; status: string; attempts: number; error: string | null; sent_at: string | null; created_at: string | null; batch_id: string | null };
type Props = {
    logs: Paginated<Log>;
    filters: { channel: string; status: string; batch: string };
    stats: { sms_used: number; sms_limit: number | null; sent: number; failed: number };
    smsAvailable: boolean;
    classes: { id: number; name: string; sections: { id: number; name: string }[] }[];
    can: { manage: boolean };
};

const tone: Record<string, string> = { queued: 'pending', sent: 'active', failed: 'suspended', skipped: 'archived' };

/** Same rule as the server: Bangla (Unicode) 70 characters per SMS, plain English 160. */
function smsParts(text: string) {
    const unicode = /[^\x20-\x7E\n\r]/.test(text);
    const [single, multi] = unicode ? [70, 67] : [160, 153];

    return text.length <= single ? 1 : Math.ceil(text.length / multi);
}

export default function MessagesIndex({ logs, filters, stats, smsAvailable, classes, can }: Props) {
    const t = useT();
    const form = useForm({ audience: 'guardians_all', class_id: '', section_id: '', channels: smsAvailable ? ['sms'] : ['mail'], message: '' });
    const errors = form.errors as Record<string, string>;
    const sections = classes.find((c) => String(c.id) === form.data.class_id)?.sections ?? [];
    const toggle = (c: string, on: boolean) => form.setData('channels', on ? [...new Set([...form.data.channels, c])] : form.data.channels.filter((x) => x !== c));
    const apply = (next: Partial<Props['filters']>) => router.get(index.url(), Object.fromEntries(Object.entries({ ...filters, ...next }).filter(([, v]) => v !== '')), { preserveState: true, preserveScroll: true });
    const reminder = form.data.audience === 'guardians_overdue';

    const columns: Column<Log>[] = [
        { key: 'to', header: t('Recipient'), cell: (l) => <><p className="font-medium">{l.name ?? '—'}</p><p className="text-xs text-muted-foreground">{l.channel === 'sms' ? 'SMS' : t('Email')} · {l.recipient}</p></> },
        { key: 'text', header: t('Message'), cell: (l) => <p className="line-clamp-2 max-w-md text-muted-foreground">{l.body}</p>, hideBelow: 'md' },
        { key: 'status', header: t('Status'), cell: (l) => (
            <div>
                <StatusBadge status={tone[l.status]} label={t(`delivery_${l.status}`)} />
                {l.error && <p className="mt-1 max-w-56 text-xs text-rose-700 dark:text-rose-400">{l.error}</p>}
            </div>
        ) },
        { key: 'when', header: t('Time'), cell: (l) => <span className="text-muted-foreground">{formatDateTime(l.sent_at ?? l.created_at)}</span>, hideBelow: 'lg' },
        { key: 'act', header: '', cell: (l) => l.status === 'failed' && <Button size="sm" variant="ghost" onClick={() => router.post(retry.url(l.id), {}, { preserveScroll: true })}><RotateCw />{t('Retry')}</Button> },
    ];

    return (
        <>
            <Head title={t('Messages')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader title={t('Messages')} description={t('SMS and email to guardians and staff. Messages are sent in the background; this page shows every delivery.')}
                    actions={can.manage && <Button variant="outline" asChild><Link href={settings()}><Settings />{t('Settings')}</Link></Button>} />

                <div className="grid gap-4 sm:grid-cols-3">
                    <StatCard label={t('SMS this month')} value={stats.sms_limit === null ? String(stats.sms_used) : `${stats.sms_used} / ${stats.sms_limit}`} hint={t('Counted in SMS parts')} />
                    <StatCard label={t('Delivered this month')} value={stats.sent} tone="success" />
                    <StatCard label={t('Failed this month')} value={stats.failed} tone={stats.failed ? 'danger' : 'default'} />
                </div>

                <form className="grid gap-4 rounded-xl border border-border/70 bg-card p-5 lg:grid-cols-2" onSubmit={(e) => { e.preventDefault(); form.post(send.url(), { preserveScroll: true, onSuccess: () => form.reset('message') }); }}>
                    <div className="grid content-start gap-3">
                        <h2 className="font-medium">{t('Send a message')}</h2>
                        <div className="grid gap-1.5"><Label htmlFor="audience">{t('Recipient')}</Label>
                            <select id="audience" className={selectClass} value={form.data.audience} onChange={(e) => form.setData('audience', e.target.value)}>
                                {['guardians_all', 'guardians_class', 'guardians_overdue', 'staff'].map((a) => <option key={a} value={a}>{t(`audience_msg_${a}`)}</option>)}
                            </select><InputError message={errors.audience} />
                        </div>
                        {form.data.audience === 'guardians_class' && (
                            <div className="grid grid-cols-2 gap-2">
                                <select aria-label={t('Class')} className={selectClass} value={form.data.class_id} onChange={(e) => form.setData((d) => ({ ...d, class_id: e.target.value, section_id: '' }))}>
                                    <option value="">{t('Class')}</option>{classes.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                                </select>
                                <select aria-label={t('Section')} className={selectClass} value={form.data.section_id} onChange={(e) => form.setData('section_id', e.target.value)}>
                                    <option value="">{t('All sections')}</option>{sections.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                                </select>
                                <InputError message={errors.class_id} />
                            </div>
                        )}
                        <fieldset className="flex flex-wrap gap-4 text-sm">
                            <legend className="sr-only">{t('Channels')}</legend>
                            <label className={cn('flex items-center gap-2', !smsAvailable && 'opacity-50')}><Checkbox disabled={!smsAvailable} checked={form.data.channels.includes('sms')} onCheckedChange={(v) => toggle('sms', v === true)} />SMS</label>
                            <label className="flex items-center gap-2"><Checkbox checked={form.data.channels.includes('mail')} onCheckedChange={(v) => toggle('mail', v === true)} />{t('Email')}</label>
                        </fieldset>
                        {!smsAvailable && <p className="text-xs text-muted-foreground">{t('SMS is not set up yet (plan or provider). Email still works.')}</p>}
                        <InputError message={errors.channels} />
                    </div>
                    <div className="grid content-start gap-2">
                        {reminder ? (
                            <p className="rounded-lg bg-muted/60 p-3 text-sm">{t('Each guardian gets the fee reminder text with their own child\'s overdue amount and due date. Edit the wording in Settings.')}</p>
                        ) : (
                            <>
                                <Label htmlFor="message">{t('Message')}</Label>
                                <textarea id="message" maxLength={480} className="min-h-28 rounded-md border border-input bg-transparent px-3 py-2 text-sm" value={form.data.message} onChange={(e) => form.setData('message', e.target.value)} />
                                <p className="text-xs text-muted-foreground">{t(':chars characters · :parts SMS part(s) per person, plus the school name', { chars: String(form.data.message.length), parts: String(smsParts(form.data.message)) })}</p>
                                <InputError message={errors.message} />
                            </>
                        )}
                        <div><Button type="submit" disabled={form.processing || form.data.channels.length === 0}>{form.processing && <Spinner />}{t('Send')}</Button></div>
                    </div>
                </form>

                <div className="flex flex-wrap items-center gap-1">
                    {['', 'sms', 'mail'].map((c) => <button key={c || 'all'} type="button" onClick={() => apply({ channel: c })} className={cn('rounded-full border px-3 py-1 text-sm', filters.channel === c ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted')}>{c === '' ? t('All') : c === 'sms' ? 'SMS' : t('Email')}</button>)}
                    <span className="mx-2 h-5 border-l" />
                    {['', 'queued', 'sent', 'failed', 'skipped'].map((s) => <button key={s || 'any'} type="button" onClick={() => apply({ status: s })} className={cn('rounded-full border px-3 py-1 text-sm', filters.status === s ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted')}>{s === '' ? t('Any status') : t(`delivery_${s}`)}</button>)}
                    {filters.batch && <button type="button" onClick={() => apply({ batch: '' })} className="ml-2 text-sm text-primary hover:underline">{t('Show all sends')}</button>}
                </div>
                {logs.data.length === 0 ? <EmptyState icon={MessageSquare} title={t('No messages yet')} /> : <DataTable columns={columns} rows={logs.data} rowKey={(l) => l.id} />}
                <Pagination page={logs} />
            </div>
        </>
    );
}

MessagesIndex.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Messages', href: index() }] };
