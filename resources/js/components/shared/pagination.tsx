import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { Paginated } from '@/types';

/** Server-side pagination footer for Laravel paginators. */
export function Pagination<T>({ page }: { page: Paginated<T> }) {
    const t = useT();

    if (page.last_page <= 1) {
        return (
            <p className="text-sm text-muted-foreground">
                {t('Showing :count of :total', { count: page.data.length, total: page.total })}
            </p>
        );
    }

    const prev = page.links[0];
    const next = page.links[page.links.length - 1];
    const numbered = page.links.slice(1, -1);

    return (
        <nav
            className="flex flex-col items-center justify-between gap-3 sm:flex-row"
            aria-label={t('Pagination')}
        >
            <p className="text-sm text-muted-foreground">
                {t(':from–:to of :total', {
                    from: page.from ?? 0,
                    to: page.to ?? 0,
                    total: page.total,
                })}
            </p>
            <div className="flex items-center gap-1">
                <PageLink url={prev.url} label={t('Previous')}>
                    <ChevronLeft className="size-4" />
                </PageLink>
                {numbered.map((link, i) =>
                    link.url === null ? (
                        <span key={i} className="px-2 text-sm text-muted-foreground">
                            …
                        </span>
                    ) : (
                        <PageLink key={i} url={link.url} active={link.active} label={link.label}>
                            {link.label}
                        </PageLink>
                    ),
                )}
                <PageLink url={next.url} label={t('Next')}>
                    <ChevronRight className="size-4" />
                </PageLink>
            </div>
        </nav>
    );
}

function PageLink({
    url,
    active,
    label,
    children,
}: {
    url: string | null;
    active?: boolean;
    label: string;
    children: React.ReactNode;
}) {
    const classes = cn(
        'inline-flex h-8 min-w-8 items-center justify-center rounded-md border px-2 text-sm tabular-nums transition-colors',
        active
            ? 'border-primary bg-primary text-primary-foreground'
            : 'border-border bg-background hover:bg-muted',
        !url && 'pointer-events-none opacity-40',
    );

    if (!url) {
        return (
            <span className={classes} aria-disabled aria-label={label}>
                {children}
            </span>
        );
    }

    return (
        <Link
            href={url}
            preserveScroll
            preserveState
            className={classes}
            aria-label={label}
            aria-current={active ? 'page' : undefined}
        >
            {children}
        </Link>
    );
}
