import { Head, router, useForm } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Eye, EyeOff, Plus, Trash2 } from 'lucide-react';
import WebsiteAdminController from '@/actions/App/Modules/Website/Http/Controllers/WebsiteAdminController';
import InputError from '@/components/input-error';
import { FormSection, selectClass } from '@/components/shared/fields';
import { PageHeader } from '@/components/shared/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { edit as website, menu as menuRoute } from '@/routes/website';
import { WebsiteNav } from './nav';

type Item = { id: number; location: string; label: string; label_bn: string | null; link_type: string; builtin: string | null; url: string | null; is_visible: boolean; page: string | null; page_status: string | null };
type Props = { items: Item[]; pages: { id: number; title: string; status: string }[]; builtins: string[] };

export default function WebsiteMenu({ items, pages, builtins }: Props) {
    const t = useT();

    return (
        <>
            <Head title={t('Menu')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader title={t('Website')} description={t('Until you add header items, the website shows a standard menu.')} />
                <WebsiteNav />
                <div className="grid gap-6 lg:grid-cols-2">
                    {(['header', 'footer'] as const).map((location) => {
                        const list = items.filter((i) => i.location === location);

                        return (
                            <FormSection key={location} title={t('menu_' + location)}>
                                <ol className="mb-4 divide-y divide-border/70 rounded-lg border border-border/70">
                                    {list.length === 0 && <li className="px-3 py-3 text-sm text-muted-foreground">{location === 'header' ? t('Standard menu in use') : t('None')}</li>}
                                    {list.map((i, n) => (
                                        <li key={i.id} className={cn('flex items-center gap-2 px-3 py-2 text-sm', !i.is_visible && 'text-muted-foreground')}>
                                            <span className="flex-1">
                                                <span className="font-medium">{i.label}</span>{i.label_bn && <span className="text-muted-foreground"> · {i.label_bn}</span>}
                                                <span className="block text-xs text-muted-foreground">{i.link_type === 'builtin' ? t('site_' + i.builtin) : i.link_type === 'page' ? `${i.page ?? '—'}${i.page_status === 'draft' ? ` (${t('draft')})` : ''}` : i.url}</span>
                                            </span>
                                            <Button size="icon" variant="ghost" disabled={n === 0} aria-label={t('Move up')} onClick={() => router.put(WebsiteAdminController.updateMenuItem.url(i.id), { move: 'up' }, { preserveScroll: true })}><ArrowUp /></Button>
                                            <Button size="icon" variant="ghost" disabled={n === list.length - 1} aria-label={t('Move down')} onClick={() => router.put(WebsiteAdminController.updateMenuItem.url(i.id), { move: 'down' }, { preserveScroll: true })}><ArrowDown /></Button>
                                            <Button size="icon" variant="ghost" aria-label={i.is_visible ? t('Hide') : t('Show')} onClick={() => router.put(WebsiteAdminController.updateMenuItem.url(i.id), { is_visible: !i.is_visible }, { preserveScroll: true })}>{i.is_visible ? <Eye /> : <EyeOff />}</Button>
                                            <Button size="icon" variant="ghost" aria-label={t('Remove')} onClick={() => router.delete(WebsiteAdminController.destroyMenuItem.url(i.id), { preserveScroll: true })}><Trash2 className="text-destructive" /></Button>
                                        </li>
                                    ))}
                                </ol>
                                <AddItem location={location} pages={pages} builtins={builtins} />
                            </FormSection>
                        );
                    })}
                </div>
            </div>
        </>
    );
}

function AddItem({ location, pages, builtins }: { location: string; pages: Props['pages']; builtins: string[] }) {
    const t = useT();
    const form = useForm({ location, label: '', label_bn: '', link_type: 'builtin', builtin: 'about', website_page_id: '', url: '' });
    const errors = form.errors as Record<string, string>;

    return (
        <div className="grid gap-2 rounded-lg bg-muted/40 p-3">
            <div className="grid grid-cols-2 gap-2">
                <Input placeholder={t('Label (English)')} value={form.data.label} onChange={(e) => form.setData('label', e.target.value)} aria-label={t('Label (English)')} />
                <Input lang="bn" placeholder={t('Label (Bangla)')} value={form.data.label_bn} onChange={(e) => form.setData('label_bn', e.target.value)} aria-label={t('Label (Bangla)')} />
            </div>
            <div className="grid grid-cols-2 gap-2">
                <select className={selectClass} value={form.data.link_type} onChange={(e) => form.setData('link_type', e.target.value)} aria-label={t('Links to')}>
                    <option value="builtin">{t('A website section')}</option><option value="page">{t('One of your pages')}</option><option value="url">{t('Another website')}</option>
                </select>
                {form.data.link_type === 'builtin' && <select className={selectClass} value={form.data.builtin} onChange={(e) => form.setData('builtin', e.target.value)} aria-label={t('Section')}>{builtins.map((b) => <option key={b} value={b}>{t('site_' + b)}</option>)}</select>}
                {form.data.link_type === 'page' && <select className={selectClass} value={form.data.website_page_id} onChange={(e) => form.setData('website_page_id', e.target.value)} aria-label={t('Page')}><option value="">—</option>{pages.map((p) => <option key={p.id} value={p.id}>{p.title}{p.status === 'draft' ? ` (${t('draft')})` : ''}</option>)}</select>}
                {form.data.link_type === 'url' && <Input placeholder="https://…" value={form.data.url} onChange={(e) => form.setData('url', e.target.value)} aria-label="URL" />}
            </div>
            <InputError message={errors.label ?? errors.url ?? errors.website_page_id ?? errors.builtin} />
            <Button size="sm" variant="outline" disabled={form.processing} onClick={() => form.post(WebsiteAdminController.storeMenuItem.url(), { preserveScroll: true, onSuccess: () => form.reset('label', 'label_bn', 'url') })}><Plus />{t('Add to menu')}</Button>
        </div>
    );
}

WebsiteMenu.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Website', href: website() }, { title: 'Menu', href: menuRoute() }] };
