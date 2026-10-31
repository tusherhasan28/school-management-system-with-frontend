import { Head, router, useForm } from '@inertiajs/react';
import { ExternalLink, Trash2, Upload } from 'lucide-react';
import WebsiteAdminController from '@/actions/App/Modules/Website/Http/Controllers/WebsiteAdminController';
import InputError from '@/components/input-error';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { Field, FormSection } from '@/components/shared/fields';
import { PageHeader } from '@/components/shared/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';
import { dashboard } from '@/routes';
import { edit as website, gallery } from '@/routes/website';
import { WebsiteNav } from './nav';

type Props = {
    album: { id: number; title: string; title_bn: string | null; description: string | null; is_public: boolean; taken_on: string | null };
    photos: { id: number; caption: string | null; caption_bn: string | null; thumb: string }[];
    siteUrl: string;
};

export default function WebsiteAlbum({ album, photos, siteUrl }: Props) {
    const t = useT();
    const details = useForm({ title: album.title, title_bn: album.title_bn ?? '', description: album.description ?? '', taken_on: album.taken_on ?? '', is_public: album.is_public });
    const upload = useForm<{ photos: File[] }>({ photos: [] });
    const uploadErrors = upload.errors as Record<string, string>;

    return (
        <>
            <Head title={album.title} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={album.title}
                    actions={
                        <>
                            {album.is_public && photos.length > 0 && <Button variant="outline" asChild><a href={siteUrl} target="_blank" rel="noreferrer"><ExternalLink />{t('View')}</a></Button>}
                            <ConfirmDialog trigger={<Button variant="ghost" className="text-destructive"><Trash2 />{t('Delete album')}</Button>} title={t('Delete this album?')} description={t('All its photos are deleted too.')} destructive onConfirm={() => router.delete(WebsiteAdminController.destroyAlbum.url(album.id))} />
                        </>
                    }
                />
                <WebsiteNav />

                <section className="rounded-xl border border-dashed border-border bg-card p-5">
                    <label className="flex cursor-pointer flex-col items-center gap-2 text-center">
                        <Upload className="size-6 text-muted-foreground" />
                        <span className="font-medium">{t('Add photos')}</span>
                        <span className="text-sm text-muted-foreground">{t('JPG, PNG or WebP, up to 20 at a time. Large photos are resized.')}</span>
                        <input type="file" multiple accept="image/jpeg,image/png,image/webp" className="sr-only" onChange={(e) => {
                            const files = Array.from(e.target.files ?? []);

                            if (files.length) {
                                upload.transform(() => ({ photos: files }));
                                upload.post(WebsiteAdminController.uploadPhotos.url(album.id), { preserveScroll: true, forceFormData: true });
                            }
                        }} />
                    </label>
                    {upload.processing && <p className="mt-2 flex items-center justify-center gap-2 text-sm"><Spinner />{t('Uploading…')}</p>}
                    <InputError className="mt-2 text-center" message={uploadErrors.photos ?? Object.entries(uploadErrors).find(([k]) => k.startsWith('photos.'))?.[1] ?? uploadErrors.image} />
                </section>

                <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    {photos.map((p) => (
                        <li key={p.id} className="group relative overflow-hidden rounded-lg border border-border/70 bg-card">
                            <img src={p.thumb} alt={p.caption ?? ''} className="aspect-[4/3] w-full object-cover" />
                            <Input className="rounded-none border-0 border-t text-xs" placeholder={t('Caption')} defaultValue={p.caption ?? ''} onBlur={(e) => e.target.value !== (p.caption ?? '') && router.put(WebsiteAdminController.updatePhoto.url(p.id), { caption: e.target.value, caption_bn: p.caption_bn }, { preserveScroll: true })} aria-label={t('Caption')} />
                            <Button size="icon" variant="secondary" className="absolute top-1.5 right-1.5 opacity-0 group-hover:opacity-100 focus:opacity-100" aria-label={t('Delete photo')} onClick={() => router.delete(WebsiteAdminController.destroyPhoto.url(p.id), { preserveScroll: true })}><Trash2 className="text-destructive" /></Button>
                        </li>
                    ))}
                </ul>

                <FormSection title={t('Album details')}>
                    <div className="grid gap-3 md:grid-cols-2">
                        <Field label={t('Title')} name="title" error={details.errors.title}><Input id="title" value={details.data.title} onChange={(e) => details.setData('title', e.target.value)} /></Field>
                        <Field label={t('Title (Bangla)')} name="title_bn"><Input id="title_bn" lang="bn" value={details.data.title_bn} onChange={(e) => details.setData('title_bn', e.target.value)} /></Field>
                        <Field label={t('Description')} name="description"><Input id="description" value={details.data.description} onChange={(e) => details.setData('description', e.target.value)} /></Field>
                        <Field label={t('Date')} name="taken_on"><Input id="taken_on" type="date" value={details.data.taken_on} onChange={(e) => details.setData('taken_on', e.target.value)} /></Field>
                    </div>
                    <label className="mt-3 flex items-center gap-2 text-sm"><input type="checkbox" className="size-4 accent-primary" checked={details.data.is_public} onChange={(e) => details.setData('is_public', e.target.checked)} />{t('Show on the website')}</label>
                    <Button className="mt-3" size="sm" disabled={details.processing} onClick={() => details.put(WebsiteAdminController.updateAlbum.url(album.id), { preserveScroll: true })}>{t('Save')}</Button>
                </FormSection>
            </div>
        </>
    );
}

WebsiteAlbum.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Website', href: website() }, { title: 'Gallery', href: gallery() }] };
