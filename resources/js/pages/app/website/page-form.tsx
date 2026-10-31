import { Head, router, useForm } from '@inertiajs/react';
import { ExternalLink, Trash2 } from 'lucide-react';
import WebsiteAdminController from '@/actions/App/Modules/Website/Http/Controllers/WebsiteAdminController';
import { BilingualField } from '@/components/shared/bilingual';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { Field, FormSection, selectClass } from '@/components/shared/fields';
import { PageHeader } from '@/components/shared/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import { dashboard } from '@/routes';
import { edit as website, pages } from '@/routes/website';
import { store as storePage, update as updatePage } from '@/routes/website/pages';
import { WebsiteNav } from './nav';

type Page = { id: number; slug: string; title: string; title_bn: string | null; body: string | null; body_bn: string | null; seo_description: string | null; status: string };

export default function WebsitePageForm({ page, siteUrl }: { page: Page | null; siteUrl: string }) {
    const t = useT();
    const form = useForm({
        title: page?.title ?? '', title_bn: page?.title_bn ?? '', slug: page?.slug ?? '', body: page?.body ?? '', body_bn: page?.body_bn ?? '',
        seo_description: page?.seo_description ?? '', status: page?.status ?? 'draft',
    });
    const errors = form.errors as Record<string, string>;
    const set = (f: string, v: string) => form.setData(f as keyof typeof form.data, v);
    const save = () => (page ? form.put(updatePage.url(page.id), { preserveScroll: true }) : form.post(storePage.url()));

    return (
        <>
            <Head title={page?.title ?? t('New page')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={page?.title ?? t('New page')}
                    actions={page && (
                        <>
                            <Button variant="outline" asChild><a href={`${siteUrl}/p/${page.slug}`} target="_blank" rel="noreferrer"><ExternalLink />{page.status === 'published' ? t('View') : t('Preview')}</a></Button>
                            <ConfirmDialog trigger={<Button variant="ghost" className="text-destructive"><Trash2 />{t('Delete')}</Button>} title={t('Delete this page?')} description={t('It is also removed from the menu.')} destructive onConfirm={() => router.delete(WebsiteAdminController.destroyPage.url(page.id))} />
                        </>
                    )}
                />
                <WebsiteNav siteUrl={siteUrl} />
                <FormSection title={t('Page')}>
                    <div className="grid gap-5">
                        <BilingualField label={t('Title')} name="title" values={form.data} onChange={set} errors={errors} required />
                        <div className="grid gap-4 md:grid-cols-2">
                            <Field label={t('Web address')} name="slug" error={errors.slug} hint={t('Lower-case letters, numbers and hyphens. Left empty, it is made from the title.')}>
                                <div className="flex items-center gap-1 text-sm"><span className="text-muted-foreground">/p/</span><Input id="slug" value={form.data.slug} onChange={(e) => set('slug', e.target.value)} /></div>
                            </Field>
                            <Field label={t('Status')} name="status">
                                <select id="status" className={selectClass} value={form.data.status} onChange={(e) => set('status', e.target.value)}><option value="draft">{t('draft')}</option><option value="published">{t('published')}</option></select>
                            </Field>
                        </div>
                        <BilingualField label={t('Content')} name="body" values={form.data} onChange={set} errors={errors} multiline rows={14} markdown />
                        <Field label={t('Description for search engines')} name="seo_description" error={errors.seo_description}><Input id="seo_description" value={form.data.seo_description} onChange={(e) => set('seo_description', e.target.value)} /></Field>
                    </div>
                </FormSection>
                <div><Button onClick={save} disabled={form.processing}>{form.processing && <Spinner />}{t('Save')}</Button></div>
            </div>
        </>
    );
}

WebsitePageForm.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Website', href: website() }, { title: 'Pages', href: pages() }] };
