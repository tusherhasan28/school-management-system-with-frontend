import { Head, router, useForm } from '@inertiajs/react';
import { ImagePlus, Trash2 } from 'lucide-react';
import WebsiteAdminController from '@/actions/App/Modules/Website/Http/Controllers/WebsiteAdminController';
import InputError from '@/components/input-error';
import { BilingualField } from '@/components/shared/bilingual';
import { Field, FormSection, selectClass } from '@/components/shared/fields';
import { PageHeader } from '@/components/shared/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { edit } from '@/routes/website';
import { WebsiteNav } from './nav';

type Settings = Record<string, string | boolean | null> & { logo_url: string | null; hero_url: string | null; principal_url: string | null };
type Teacher = { id: number; full_name: string; designation: string | null; show_on_website: boolean };

type Props = { settings: Settings; teachers: Teacher[]; siteUrl: string; templates: string[] };

const textFields = ['tagline', 'hero_title', 'principal_name', 'office_hours', 'hero_text', 'about', 'principal_message', 'seo_description'];

export default function WebsiteSettings({ settings, teachers, siteUrl, templates }: Props) {
    const t = useT();
    const initial: Record<string, string | boolean> = {
        template: String(settings.template), theme_color: String(settings.theme_color), default_language: String(settings.default_language),
        is_published: Boolean(settings.is_published), show_teachers: Boolean(settings.show_teachers),
        eiin: (settings.eiin as string) ?? '', facebook_url: (settings.facebook_url as string) ?? '', youtube_url: (settings.youtube_url as string) ?? '', map_url: (settings.map_url as string) ?? '',
    };
    textFields.forEach((f) => { initial[f] = (settings[f] as string) ?? ''; initial[f + '_bn'] = (settings[f + '_bn'] as string) ?? ''; });
    const form = useForm(initial);
    const errors = form.errors as Record<string, string>;
    const set = (field: string, value: string | boolean) => form.setData(field, value);
    const shown = useForm({ shown: teachers.filter((x) => x.show_on_website).map((x) => x.id) });

    const save = () => form.put(WebsiteAdminController.update.url(), { preserveScroll: true });

    return (
        <>
            <Head title={t('Website')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader title={t('Website')} description={t('Your school\'s public website: look, home page, about, contact and search engine text.')} />
                <WebsiteNav siteUrl={siteUrl} />

                <section className={cn('flex flex-wrap items-center justify-between gap-3 rounded-xl border p-4', form.data.is_published ? 'border-emerald-300/60 bg-emerald-50 dark:bg-emerald-500/10' : 'border-amber-300/60 bg-amber-50 dark:bg-amber-500/10')}>
                    <div className="text-sm">
                        <p className="font-medium">{form.data.is_published ? t('The website is live.') : t('The website is not published yet.')}</p>
                        <p className="text-muted-foreground">{form.data.is_published ? siteUrl : t('Visitors see a short holding page with the admission link. You can preview everything while signed in.')}</p>
                    </div>
                    <label className="flex items-center gap-2 text-sm font-medium">
                        <input type="checkbox" className="size-4 accent-primary" checked={Boolean(form.data.is_published)} onChange={(e) => set('is_published', e.target.checked)} />{t('Published')}
                    </label>
                </section>

                <FormSection title={t('Appearance')}>
                    <div className="grid gap-4 md:grid-cols-3">
                        <fieldset className="grid gap-2 md:col-span-3">
                            <legend className="mb-1 text-sm font-medium">{t('Template')}</legend>
                            <div className="grid gap-3 sm:grid-cols-2">
                                {templates.map((tpl) => (
                                    <label key={tpl} className={cn('flex cursor-pointer gap-3 rounded-lg border p-3 text-sm', form.data.template === tpl ? 'border-primary ring-2 ring-primary/20' : 'border-border')}>
                                        <input type="radio" name="template" className="mt-0.5 accent-primary" checked={form.data.template === tpl} onChange={() => set('template', tpl)} />
                                        <span><span className="block font-medium">{t('template_name_' + tpl)}</span><span className="text-muted-foreground">{t('template_desc_' + tpl)}</span></span>
                                    </label>
                                ))}
                            </div>
                        </fieldset>
                        <Field label={t('Theme colour')} name="theme_color" error={errors.theme_color}>
                            <div className="flex gap-2"><input type="color" className="h-9 w-12 rounded border" value={String(form.data.theme_color)} onChange={(e) => set('theme_color', e.target.value)} aria-label={t('Theme colour')} /><Input id="theme_color" value={String(form.data.theme_color)} onChange={(e) => set('theme_color', e.target.value)} /></div>
                        </Field>
                        <Field label={t('Default language')} name="default_language">
                            <select id="default_language" className={selectClass} value={String(form.data.default_language)} onChange={(e) => set('default_language', e.target.value)}><option value="bn">বাংলা</option><option value="en">English</option></select>
                        </Field>
                        <Field label={t('EIIN')} name="eiin" error={errors.eiin} hint={t('Educational Institute Identification Number, shown in the header.')}><Input id="eiin" inputMode="numeric" value={String(form.data.eiin)} onChange={(e) => set('eiin', e.target.value)} /></Field>
                    </div>
                    <div className="mt-5 grid gap-4 sm:grid-cols-3">
                        <ImageSlot slot="logo" label={t('Logo')} url={settings.logo_url} />
                        <ImageSlot slot="hero" label={t('Home page banner')} url={settings.hero_url} wide />
                        <ImageSlot slot="principal" label={t('Principal photo')} url={settings.principal_url} />
                    </div>
                </FormSection>

                <FormSection title={t('Home page')}>
                    <div className="grid gap-5">
                        <BilingualField label={t('Tagline')} name="tagline" values={form.data} onChange={set} errors={errors} />
                        <BilingualField label={t('Banner heading')} name="hero_title" values={form.data} onChange={set} errors={errors} />
                        <BilingualField label={t('Banner text')} name="hero_text" values={form.data} onChange={set} errors={errors} multiline rows={2} />
                    </div>
                </FormSection>

                <FormSection title={t('About and principal')}>
                    <div className="grid gap-5">
                        <BilingualField label={t('About the school')} name="about" values={form.data} onChange={set} errors={errors} multiline rows={8} markdown />
                        <BilingualField label={t('Principal\'s name')} name="principal_name" values={form.data} onChange={set} errors={errors} />
                        <BilingualField label={t('Principal\'s message')} name="principal_message" values={form.data} onChange={set} errors={errors} multiline rows={6} markdown />
                    </div>
                </FormSection>

                <FormSection title={t('Contact and search engines')} description={t('Address, phone and email come from the school profile.')}>
                    <div className="grid gap-5">
                        <BilingualField label={t('Office hours')} name="office_hours" values={form.data} onChange={set} errors={errors} />
                        <div className="grid gap-4 md:grid-cols-3">
                            <Field label="Facebook" name="facebook_url" error={errors.facebook_url}><Input id="facebook_url" placeholder="https://facebook.com/…" value={String(form.data.facebook_url)} onChange={(e) => set('facebook_url', e.target.value)} /></Field>
                            <Field label="YouTube" name="youtube_url" error={errors.youtube_url}><Input id="youtube_url" placeholder="https://youtube.com/…" value={String(form.data.youtube_url)} onChange={(e) => set('youtube_url', e.target.value)} /></Field>
                            <Field label={t('Google Maps embed link')} name="map_url" error={errors.map_url}><Input id="map_url" placeholder="https://www.google.com/maps/embed?…" value={String(form.data.map_url)} onChange={(e) => set('map_url', e.target.value)} /></Field>
                        </div>
                        <BilingualField label={t('Description for search engines')} name="seo_description" values={form.data} onChange={set} errors={errors} multiline rows={2} hint={t('One or two sentences shown under your school\'s name in Google results.')} />
                    </div>
                </FormSection>

                <div className="sticky bottom-0 z-10 -mx-4 border-t border-border/70 bg-background/95 px-4 py-3 backdrop-blur md:-mx-6 md:px-6">
                    <Button onClick={save} disabled={form.processing}>{form.processing && <Spinner />}{t('Save website settings')}</Button>
                </div>

                <FormSection title={t('Teachers on the website')} description={t('Only the name and designation of the teachers you tick are shown. Nobody appears until you choose them.')}>
                    <label className="mb-3 flex items-center gap-2 text-sm"><input type="checkbox" className="size-4 accent-primary" checked={Boolean(form.data.show_teachers)} onChange={(e) => set('show_teachers', e.target.checked)} />{t('Show a Teachers page (save settings above)')}</label>
                    <ul className="grid gap-1 sm:grid-cols-2 lg:grid-cols-3">
                        {teachers.map((x) => (
                            <li key={x.id}>
                                <label className="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-muted">
                                    <input type="checkbox" className="size-4 accent-primary" checked={shown.data.shown.includes(x.id)} onChange={(e) => shown.setData('shown', e.target.checked ? [...shown.data.shown, x.id] : shown.data.shown.filter((id) => id !== x.id))} />
                                    <span>{x.full_name}{x.designation && <span className="text-muted-foreground"> · {x.designation}</span>}</span>
                                </label>
                            </li>
                        ))}
                        {teachers.length === 0 && <li className="text-sm text-muted-foreground">{t('No active teachers.')}</li>}
                    </ul>
                    <Button className="mt-3" size="sm" variant="outline" disabled={shown.processing} onClick={() => shown.put(WebsiteAdminController.teachers.url(), { preserveScroll: true })}>{t('Save teachers')}</Button>
                </FormSection>
            </div>
        </>
    );
}

function ImageSlot({ slot, label, url, wide = false }: { slot: string; label: string; url: string | null; wide?: boolean }) {
    const t = useT();
    const form = useForm<{ image: File | null }>({ image: null });

    return (
        <div className="grid gap-2">
            <span className="text-sm font-medium">{label}</span>
            <div className={cn('flex items-center justify-center overflow-hidden rounded-lg border border-dashed bg-muted/40', wide ? 'aspect-[16/7]' : 'aspect-square max-w-40')}>
                {url ? <img src={url} alt="" className="size-full object-cover" /> : <ImagePlus className="size-6 text-muted-foreground" />}
            </div>
            <div className="flex items-center gap-2">
                <label className="cursor-pointer text-sm font-medium text-primary hover:underline">
                    {url ? t('Replace') : t('Upload')}
                    <input type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" onChange={(e) => {
                        const file = e.target.files?.[0];

                        if (file) {
                            form.transform(() => ({ image: file }));
                            form.post(WebsiteAdminController.uploadImage.url(slot), { preserveScroll: true, forceFormData: true });
                        }
                    }} />
                </label>
                {form.processing && <Spinner />}
                {url && <Button size="icon" variant="ghost" aria-label={t('Remove')} onClick={() => router.delete(WebsiteAdminController.removeImage.url(slot), { preserveScroll: true })}><Trash2 className="text-destructive" /></Button>}
            </div>
            <InputError message={(form.errors as Record<string, string>).image} />
        </div>
    );
}

WebsiteSettings.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Website', href: edit() }] };
