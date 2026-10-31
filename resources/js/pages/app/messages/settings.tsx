import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { FormSection } from '@/components/shared/fields';
import { PageHeader } from '@/components/shared/page-header';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useT } from '@/lib/i18n';
import { dashboard } from '@/routes';
import { automatic, index, provider as saveProvider, settings as settingsRoute, template as saveTemplate } from '@/routes/messages';

type Provider = { key: string; label: string; fields: { key: string; label: string; secret: boolean }[]; per_minute: number; enabled: boolean; sender_id: string | null; has_credentials: boolean };
type Template = {
    key: string; placeholders: string[];
    sms: { default_en: string; default_bn: string; body: string | null; body_bn: string | null };
    mail: { default_en: string; default_bn: string; default_subject_en: string; default_subject_bn: string; subject: string | null; subject_bn: string | null; body: string | null; body_bn: string | null };
};
type Props = { providers: Provider[]; smsInPlan: boolean; automatic: { key: string; channels: string[] }[]; templates: Template[] };

export default function MessageSettings({ providers, smsInPlan, automatic: auto, templates }: Props) {
    const t = useT();

    return (
        <>
            <Head title={t('Message settings')} />
            <div className="flex max-w-4xl flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader title={t('Message settings')} description={t('SMS provider, automatic messages and the wording of each message in Bangla and English.')} />

                <FormSection title={t('SMS provider')} description={smsInPlan ? t('Credentials are stored encrypted and are never shown again.') : t('SMS is not included in your plan.')}>
                    {providers.length === 0 ? <p className="text-sm text-muted-foreground">{t('No SMS provider is installed on this platform yet. Ask the platform team.')}</p> : providers.map((p) => <ProviderForm key={p.key} provider={p} />)}
                </FormSection>

                <FormSection title={t('Automatic messages')} description={t('Sent by the system when attendance is finalized or results are published.')}>
                    <ul className="divide-y text-sm">
                        {auto.map((a) => (
                            <li key={a.key} className="flex flex-wrap items-center justify-between gap-3 py-2">
                                <span>{t(`template_msg_${a.key}`)}</span>
                                <span className="flex gap-4">
                                    {['sms', 'mail'].map((c) => (
                                        <label key={c} className="flex items-center gap-2">
                                            <Checkbox checked={a.channels.includes(c)} onCheckedChange={(v) => router.put(automatic.url(), { key: a.key, channels: v === true ? [...a.channels, c] : a.channels.filter((x) => x !== c) }, { preserveScroll: true })} />
                                            {c === 'sms' ? 'SMS' : t('Email')}
                                        </label>
                                    ))}
                                </span>
                            </li>
                        ))}
                    </ul>
                    <p className="mt-2 text-xs text-muted-foreground">{t('Absence alerts also need "Notify guardians of absences" switched on in Calendar & holidays.')}</p>
                </FormSection>

                <FormSection title={t('Message wording')} description={t('Leave a box empty to use the standard text shown as a placeholder.')}>
                    <div className="grid gap-4">{templates.map((tpl) => <TemplateEditor key={tpl.key} tpl={tpl} />)}</div>
                </FormSection>
            </div>
        </>
    );
}

function ProviderForm({ provider }: { provider: Provider }) {
    const t = useT();
    const form = useForm({ provider: provider.key, sender_id: provider.sender_id ?? '', is_enabled: provider.enabled, credentials: Object.fromEntries(provider.fields.map((f) => [f.key, ''])) as Record<string, string> });

    return (
        <form className="grid gap-3 rounded-lg border p-4" onSubmit={(e) => { e.preventDefault(); form.put(saveProvider.url(), { preserveScroll: true }); }}>
            <p className="font-medium">{provider.label} <span className="text-xs font-normal text-muted-foreground">· {t(':count per minute', { count: String(provider.per_minute) })}</span></p>
            <div className="grid gap-3 sm:grid-cols-2">
                <div className="grid gap-1.5"><Label htmlFor={`sid-${provider.key}`}>{t('Sender ID / mask')}</Label><Input id={`sid-${provider.key}`} value={form.data.sender_id} onChange={(e) => form.setData('sender_id', e.target.value)} /><InputError message={form.errors.sender_id} /></div>
                {provider.fields.map((f) => (
                    <div key={f.key} className="grid gap-1.5">
                        <Label htmlFor={`cred-${f.key}`}>{f.label}</Label>
                        <Input id={`cred-${f.key}`} type={f.secret ? 'password' : 'text'} autoComplete="off" placeholder={provider.has_credentials ? t('Saved — leave blank to keep') : ''} value={form.data.credentials[f.key]} onChange={(e) => form.setData('credentials', { ...form.data.credentials, [f.key]: e.target.value })} />
                    </div>
                ))}
            </div>
            <label className="flex items-center gap-2 text-sm"><Checkbox checked={form.data.is_enabled} onCheckedChange={(v) => form.setData('is_enabled', v === true)} />{t('Use this provider')}</label>
            <div><Button type="submit" disabled={form.processing}>{t('Save')}</Button></div>
        </form>
    );
}

function TemplateEditor({ tpl }: { tpl: Template }) {
    const t = useT();
    const [channel, setChannel] = useState<'sms' | 'mail'>('sms');
    const sms = useForm({ key: tpl.key, channel: 'sms', body: tpl.sms.body ?? '', body_bn: tpl.sms.body_bn ?? '' });
    const mail = useForm({ key: tpl.key, channel: 'mail', subject: tpl.mail.subject ?? '', subject_bn: tpl.mail.subject_bn ?? '', body: tpl.mail.body ?? '', body_bn: tpl.mail.body_bn ?? '' });
    const area = 'min-h-20 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm';

    return (
        <div className="rounded-lg border p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="font-medium">{t(`template_msg_${tpl.key}`)}</p>
                <div className="flex gap-1">{(['sms', 'mail'] as const).map((c) => <Button key={c} type="button" size="sm" variant={channel === c ? 'default' : 'outline'} onClick={() => setChannel(c)}>{c === 'sms' ? 'SMS' : t('Email')}</Button>)}</div>
            </div>
            <p className="mt-1 text-xs text-muted-foreground">{t('Placeholders')}: {tpl.placeholders.map((p) => `{${p}}`).join(' ')}</p>
            {channel === 'sms' ? (
                <form className="mt-3 grid gap-2" onSubmit={(e) => { e.preventDefault(); sms.put(saveTemplate.url(), { preserveScroll: true }); }}>
                    <Label>{t('Bangla')}</Label><textarea lang="bn" className={area} placeholder={tpl.sms.default_bn} value={sms.data.body_bn} onChange={(e) => sms.setData('body_bn', e.target.value)} />
                    <Label>{t('English')}</Label><textarea className={area} placeholder={tpl.sms.default_en} value={sms.data.body} onChange={(e) => sms.setData('body', e.target.value)} />
                    <InputError message={sms.errors.body} />
                    <div><Button size="sm" type="submit" disabled={sms.processing}>{t('Save')}</Button></div>
                </form>
            ) : (
                <form className="mt-3 grid gap-2" onSubmit={(e) => { e.preventDefault(); mail.put(saveTemplate.url(), { preserveScroll: true }); }}>
                    <Label>{t('Subject (Bangla)')}</Label><Input lang="bn" placeholder={tpl.mail.default_subject_bn} value={mail.data.subject_bn} onChange={(e) => mail.setData('subject_bn', e.target.value)} />
                    <Label>{t('Text (Bangla)')}</Label><textarea lang="bn" className={area} placeholder={tpl.mail.default_bn} value={mail.data.body_bn} onChange={(e) => mail.setData('body_bn', e.target.value)} />
                    <Label>{t('Subject (English)')}</Label><Input placeholder={tpl.mail.default_subject_en} value={mail.data.subject} onChange={(e) => mail.setData('subject', e.target.value)} />
                    <Label>{t('Text (English)')}</Label><textarea className={area} placeholder={tpl.mail.default_en} value={mail.data.body} onChange={(e) => mail.setData('body', e.target.value)} />
                    <div><Button size="sm" type="submit" disabled={mail.processing}>{t('Save')}</Button></div>
                </form>
            )}
        </div>
    );
}

MessageSettings.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Messages', href: index() }, { title: 'Settings', href: settingsRoute() }] };
