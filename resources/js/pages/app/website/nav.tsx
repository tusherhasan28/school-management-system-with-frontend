import { Link, usePage } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { edit, gallery, menu, pages } from '@/routes/website';

/** Sub-navigation shared by the website builder screens. */
export function WebsiteNav({ siteUrl }: { siteUrl?: string }) {
    const t = useT();
    const { url } = usePage();
    const items = [
        { href: edit.url(), label: t('Settings'), match: (u: string) => u === '/app/website' || u.startsWith('/app/website?') },
        { href: pages.url(), label: t('Pages'), match: (u: string) => u.startsWith('/app/website/pages') },
        { href: menu.url(), label: t('Menu'), match: (u: string) => u.startsWith('/app/website/menu') },
        { href: gallery.url(), label: t('Gallery'), match: (u: string) => u.startsWith('/app/website/gallery') || u.startsWith('/app/website/albums') },
    ];

    return (
        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-border/70">
            <nav className="-mb-px flex gap-1" aria-label={t('Website')}>
                {items.map((i) => (
                    <Link key={i.href} href={i.href} className={cn('border-b-2 px-3 py-2 text-sm font-medium', i.match(url) ? 'border-primary text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground')}>{i.label}</Link>
                ))}
            </nav>
            {siteUrl && <Button variant="ghost" size="sm" asChild><a href={siteUrl} target="_blank" rel="noreferrer"><ExternalLink />{t('View website')}</a></Button>}
        </div>
    );
}
