import { Head, Link } from '@inertiajs/react';
import { FileText, Plus } from 'lucide-react';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { StatusBadge } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import { formatDateTime, useT } from '@/lib/i18n';
import { dashboard } from '@/routes';
import { edit as website, pages as pagesRoute } from '@/routes/website';
import { create, edit } from '@/routes/website/pages';
import { WebsiteNav } from './nav';

type Props = { pages: { id: number; slug: string; title: string; title_bn: string | null; status: string; updated_at: string }[]; siteUrl: string };

export default function WebsitePages({ pages, siteUrl }: Props) {
    const t = useT();

    return (
        <>
            <Head title={t('Pages')} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader title={t('Website')} description={t('Extra pages such as history, facilities, rules or uniform. Add them to the menu afterwards.')} actions={<Button asChild><Link href={create()}><Plus />{t('New page')}</Link></Button>} />
                <WebsiteNav siteUrl={siteUrl} />
                {pages.length === 0 ? <EmptyState icon={FileText} title={t('No pages yet')} /> : (
                    <ul className="divide-y divide-border/70 overflow-hidden rounded-xl border border-border/70 bg-card">
                        {pages.map((p) => (
                            <li key={p.id}>
                                <Link href={edit(p.id)} className="flex items-center justify-between gap-3 px-4 py-3 hover:bg-muted/30">
                                    <span><span className="font-medium">{p.title}</span>{p.title_bn && <span className="ml-2 text-muted-foreground">· {p.title_bn}</span>}<span className="block font-mono text-xs text-muted-foreground">/p/{p.slug} · {formatDateTime(p.updated_at)}</span></span>
                                    <StatusBadge status={p.status === 'published' ? 'active' : 'archived'} label={t(p.status)} />
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

WebsitePages.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Website', href: website() }, { title: 'Pages', href: pagesRoute() }] };
