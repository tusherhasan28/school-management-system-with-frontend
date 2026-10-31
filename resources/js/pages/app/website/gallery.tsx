import { Form, Head, Link } from '@inertiajs/react';
import { Images, Lock } from 'lucide-react';
import WebsiteAdminController from '@/actions/App/Modules/Website/Http/Controllers/WebsiteAdminController';
import { EmptyState } from '@/components/shared/empty-state';
import { FormSection, TextField } from '@/components/shared/fields';
import { PageHeader } from '@/components/shared/page-header';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatDate, useT } from '@/lib/i18n';
import { dashboard } from '@/routes';
import { edit as website, gallery as galleryRoute } from '@/routes/website';
import { show } from '@/routes/website/albums';
import { WebsiteNav } from './nav';

type Album = { id: number; title: string; title_bn: string | null; is_public: boolean; photos_count: number; taken_on: string | null; cover: string | null };

export default function WebsiteGallery({ albums }: { albums: Album[] }) {
    const t = useT();

    return (
        <>
            <Head title={t('Gallery')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader title={t('Website')} description={t('Photo albums for the website. Location data is removed from every photo when it is uploaded.')} />
                <WebsiteNav />
                <div className="grid gap-6 lg:grid-cols-3">
                    <div className="lg:col-span-2">
                        {albums.length === 0 ? <EmptyState icon={Images} title={t('No albums yet')} /> : (
                            <ul className="grid gap-4 sm:grid-cols-2">
                                {albums.map((a) => (
                                    <li key={a.id}>
                                        <Link href={show(a.id)} className="block overflow-hidden rounded-xl border border-border/70 bg-card hover:border-primary/40">
                                            {a.cover ? <img src={a.cover} alt="" className="aspect-[4/3] w-full object-cover" /> : <div className="flex aspect-[4/3] items-center justify-center bg-muted"><Images className="text-muted-foreground" /></div>}
                                            <div className="p-3">
                                                <p className="flex items-center gap-1.5 font-medium">{!a.is_public && <Lock className="size-3.5 text-muted-foreground" aria-label={t('Hidden')} />}{a.title}</p>
                                                <p className="text-sm text-muted-foreground">{t(':count photos', { count: a.photos_count })}{a.taken_on ? ` · ${formatDate(a.taken_on)}` : ''}</p>
                                            </div>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                    <Form {...WebsiteAdminController.storeAlbum.form()}>
                        {({ processing, errors }) => (
                            <FormSection title={t('New album')}>
                                <div className="grid gap-3">
                                    <TextField label={t('Title')} name="title" required error={errors.title} />
                                    <TextField label={t('Title (Bangla)')} name="title_bn" lang="bn" error={errors.title_bn} />
                                    <TextField label={t('Date')} name="taken_on" type="date" error={errors.taken_on} />
                                    <input type="hidden" name="is_public" value="1" />
                                    <Button type="submit" disabled={processing}>{processing && <Spinner />}{t('Create')}</Button>
                                </div>
                            </FormSection>
                        )}
                    </Form>
                </div>
            </div>
        </>
    );
}

WebsiteGallery.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Website', href: website() }, { title: 'Gallery', href: galleryRoute() }] };
