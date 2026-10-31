import { Head, useForm } from '@inertiajs/react';
import { Paperclip } from 'lucide-react';
import NoticeController from '@/actions/App/Modules/Notices/Http/Controllers/NoticeController';
import InputError from '@/components/input-error';
import { BilingualField } from '@/components/shared/bilingual';
import { Field, FormSection } from '@/components/shared/fields';
import { PageHeader } from '@/components/shared/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { attachment, index } from '@/routes/notices';

type Notice = {
    id: number; title: string; title_bn: string | null; body: string | null; body_bn: string | null; audiences: string[]; is_pinned: boolean;
    status: string; attachment_name: string | null; publish_at: string | null; expires_at: string | null;
};

const audiences = ['public', 'staff', 'parents', 'students'];

export default function NoticeForm({ notice }: { notice: Notice | null }) {
    const t = useT();
    const form = useForm({
        title: notice?.title ?? '', title_bn: notice?.title_bn ?? '', body: notice?.body ?? '', body_bn: notice?.body_bn ?? '',
        audiences: notice?.audiences ?? ['public'], is_pinned: notice?.is_pinned ?? false, status: notice?.status ?? 'published',
        publish_at: notice?.publish_at ?? '', expires_at: notice?.expires_at ?? '', attachment: null as File | null, remove_attachment: false,
    });
    const errors = form.errors as Record<string, string>;
    const toggle = (a: string) => form.setData('audiences', form.data.audiences.includes(a) ? form.data.audiences.filter((x) => x !== a) : [...form.data.audiences, a]);
    const save = (status: string) => {
        form.transform((d) => ({ ...d, status }));
        form.post(notice ? NoticeController.update.url(notice.id) : NoticeController.store.url(), { forceFormData: true });
    };

    return (
        <>
            <Head title={notice ? notice.title : t('New notice')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader title={notice ? notice.title : t('New notice')} />
                <FormSection title={t('Notice')}>
                    <div className="grid gap-5">
                        <BilingualField label={t('Title')} name="title" values={form.data} onChange={(f, v) => form.setData(f as 'title', v)} errors={errors} required />
                        <BilingualField label={t('Text')} name="body" values={form.data} onChange={(f, v) => form.setData(f as 'body', v)} errors={errors} multiline rows={10} markdown />
                    </div>
                </FormSection>

                <FormSection title={t('Who sees it')}>
                    <div className="grid gap-2 sm:grid-cols-2">
                        {audiences.map((a) => (
                            <label key={a} className={cn('flex cursor-pointer gap-3 rounded-lg border p-3 text-sm', form.data.audiences.includes(a) ? 'border-primary bg-primary/5' : 'border-border')}>
                                <input type="checkbox" className="mt-0.5 size-4 accent-primary" checked={form.data.audiences.includes(a)} onChange={() => toggle(a)} />
                                <span><span className="block font-medium">{t('audience_' + a)}</span><span className="text-muted-foreground">{t('audience_hint_' + a)}</span></span>
                            </label>
                        ))}
                    </div>
                    <InputError className="mt-2" message={errors.audiences} />
                </FormSection>

                <FormSection title={t('When and how')}>
                    <div className="grid gap-4 md:grid-cols-3">
                        <Field label={t('Publish from')} name="publish_at" hint={t('Leave empty to publish now.')} error={errors.publish_at}><Input id="publish_at" type="datetime-local" value={form.data.publish_at} onChange={(e) => form.setData('publish_at', e.target.value)} /></Field>
                        <Field label={t('Remove after')} name="expires_at" hint={t('Optional.')} error={errors.expires_at}><Input id="expires_at" type="datetime-local" value={form.data.expires_at} onChange={(e) => form.setData('expires_at', e.target.value)} /></Field>
                        <Field label={t('Attachment')} name="attachment" hint={t('PDF, image, Word or Excel up to 10 MB.')} error={errors.attachment}>
                            <Input id="attachment" type="file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx" onChange={(e) => form.setData('attachment', e.target.files?.[0] ?? null)} />
                        </Field>
                    </div>
                    {notice?.attachment_name && !form.data.attachment && (
                        <p className="mt-3 flex flex-wrap items-center gap-3 text-sm">
                            <a href={attachment.url(notice.id)} className="inline-flex items-center gap-1 text-primary hover:underline"><Paperclip className="size-4" />{notice.attachment_name}</a>
                            <label className="flex items-center gap-1.5"><input type="checkbox" className="size-4 accent-primary" checked={form.data.remove_attachment} onChange={(e) => form.setData('remove_attachment', e.target.checked)} />{t('Remove')}</label>
                        </p>
                    )}
                    <label className="mt-4 flex items-center gap-2 text-sm"><input type="checkbox" className="size-4 accent-primary" checked={form.data.is_pinned} onChange={(e) => form.setData('is_pinned', e.target.checked)} />{t('Pin to the top and mark as important')}</label>
                </FormSection>

                <div className="flex gap-2">
                    <Button onClick={() => save('published')} disabled={form.processing}>{form.processing && <Spinner />}{t('Publish')}</Button>
                    <Button variant="outline" onClick={() => save('draft')} disabled={form.processing}>{t('Save draft')}</Button>
                </div>
            </div>
        </>
    );
}

NoticeForm.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Notices', href: index() }] };
